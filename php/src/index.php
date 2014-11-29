<?php
session_start();

$host = getenv('ISU4_DB_HOST') ?: 'localhost';
$port = getenv('ISU4_DB_PORT') ?: 3306;
$dbname = getenv('ISU4_DB_NAME') ?: 'isu4_qualifier';
$username = getenv('ISU4_DB_USER') ?: 'root';
$password = getenv('ISU4_DB_PASSWORD');
$db = null;

try {
  $db = new PDO(
    'mysql:host=' . $host . ';port=' . $port. ';dbname=' . $dbname,
    $username,
    $password,
    [
      PDO::ATTR_PERSISTENT => true,
      PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
    ]
  );
} catch (PDOException $e) {
  die();
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$config = [
  'user_lock_threshold' => getenv('ISU4_USER_LOCK_THRESHOLD') ?: 3,
  'ip_ban_threshold' => getenv('ISU4_IP_BAN_THRESHOLD') ?: 10
];

function calculate_password_hash($password, $salt) {
  return hash('sha256', $password . ':' . $salt);
}

function login_log($succeeded, $login, $user_id=null) {
  global $db;

  $stmt = $db->prepare('INSERT INTO login_log (`created_at`, `user_id`, `login`, `ip`, `succeeded`) VALUES (NOW(),:user_id,:login,:ip,:succeeded)');
  $stmt->bindValue(':user_id', $user_id);
  $stmt->bindValue(':login', $login);
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
  $stmt->bindValue(':succeeded', $succeeded ? 1 : 0);
  $stmt->execute();
}

function user_locked($user) {
  if (empty($user)) { return null; }

  global $db, $config;

  $stmt = $db->prepare('SELECT COUNT(1) AS failures FROM login_log WHERE user_id = :user_id AND id > IFNULL((select id from login_log where user_id = :user_id AND succeeded = 1 ORDER BY id DESC LIMIT 1), 0)');
  $stmt->bindValue(':user_id', $user['id']);
  $stmt->execute();
  $log = $stmt->fetch(PDO::FETCH_ASSOC);

  return $config['user_lock_threshold'] <= $log['failures'];
}

function ip_banned() {
  global $db, $config;

  $stmt = $db->prepare('SELECT COUNT(1) AS failures FROM login_log WHERE ip = :ip AND id > IFNULL((select id from login_log where ip = :ip AND succeeded = 1 ORDER BY id DESC LIMIT 1), 0)');
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
  $stmt->execute();
  $log = $stmt->fetch(PDO::FETCH_ASSOC);

  return $config['ip_ban_threshold'] <= $log['failures'];
}

function attempt_login($login, $password) {
  global $db;

  $stmt = $db->prepare('SELECT * FROM users WHERE login = :login');
  $stmt->bindValue(':login', $login);
  $stmt->execute();
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (ip_banned()) {
    login_log(false, $login, isset($user['id']) ? $user['id'] : null);
    return ['error' => 'banned'];
  }

  if (user_locked($user)) {
    login_log(false, $login, $user['id']);
    return ['error' => 'locked'];
  }

  if (!empty($user) && calculate_password_hash($password, $user['salt']) == $user['password_hash']) {
    login_log(true, $login, $user['id']);
    return ['user' => $user];
  }
  elseif (!empty($user)) {
    login_log(false, $login, $user['id']);
    return ['error' => 'wrong_password'];
  }
  else {
    login_log(false, $login);
    return ['error' => 'wrong_login'];
  }
}

function current_user() {
  if (empty($_SESSION['user_id'])) {
    return null;
  }

  global $db;

  $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
  $stmt->bindValue(':id', $_SESSION['user_id']);
  $stmt->execute();
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (empty($user)) {
    unset($_SESSION['user_id']);
    return null;
  }

  return $user;
}

function last_login() {
  $user = current_user();

  if (empty($user)) {
    return null;
  }

  global $db;

  $stmt = $db->prepare('SELECT * FROM login_log WHERE succeeded = 1 AND user_id = :id ORDER BY id DESC LIMIT 2');
  $stmt->bindValue(':id', $user['id']);
  $stmt->execute();
  $stmt->fetch();
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

function banned_ips() {
  global $config;

  $threshold = $config['ip_ban_threshold'];
  $ips = [];

  global $db;

  $stmt = $db->prepare('SELECT ip FROM (SELECT ip, MAX(succeeded) as max_succeeded, COUNT(1) as cnt FROM login_log GROUP BY ip) AS t0 WHERE t0.max_succeeded = 0 AND t0.cnt >= :threshold');
  $stmt->bindValue(':threshold', $threshold);
  $stmt->execute();
  $not_succeeded = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  $ips = array_merge($not_succeeded);

  $stmt = $db->prepare('SELECT ip, MAX(id) AS last_login_id FROM login_log WHERE succeeded = 1 GROUP by ip');
  $stmt->execute();
  $last_succeeds = $stmt->fetchAll();

  foreach ($last_succeeds as $row) {
    $stmt = $db->prepare('SELECT COUNT(1) AS cnt FROM login_log WHERE ip = :ip AND :id < id');
    $stmt->bindValue(':ip', $row['ip']);
    $stmt->bindValue(':id', $row['last_login_id']);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    if ($threshold <= $count) {
      array_push($ips, $row['ip']);
    }
  }

  return $ips;
}

function locked_users() {
  global $config;

  $threshold = $config['user_lock_threshold'];
  $user_ids = [];

  global $db;

  $stmt = $db->prepare('SELECT login FROM (SELECT user_id, login, MAX(succeeded) as max_succeeded, COUNT(1) as cnt FROM login_log GROUP BY user_id) AS t0 WHERE t0.user_id IS NOT NULL AND t0.max_succeeded = 0 AND t0.cnt >= :threshold');
  $stmt->bindValue(':threshold', $threshold);
  $stmt->execute();
  $not_succeeded = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  $user_ids = array_merge($not_succeeded);

  $stmt = $db->prepare('SELECT user_id, login, MAX(id) AS last_login_id FROM login_log WHERE user_id IS NOT NULL AND succeeded = 1 GROUP BY user_id');
  $stmt->execute();
  $last_succeeds = $stmt->fetchAll();

  foreach ($last_succeeds as $row) {
    $stmt = $db->prepare('SELECT COUNT(1) AS cnt FROM login_log WHERE user_id = :user_id AND :id < id');
    $stmt->bindValue(':user_id', $row['user_id']);
    $stmt->bindValue(':id', $row['last_login_id']);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    if ($threshold <= $count) {
      array_push($user_ids, $row['login']);
    }
  }

  return $user_ids;
}

function is($path) {
  return array_key_exists($path, $_REQUEST);
}

function render($template, $values) {
  extract($values);

  include('views/' . $template);
}

function redirect($url) {
  header('Location: ' . $url);
}

if (is('/')) {
  if (isset($_SESSION['notice'])) {
    $notice = $_SESSION['notice'];
    unset($_SESSION['notice']);
  }
  else {
    $notice = null;
  }

  return render('index.html.php', [
    'notice' => $notice
  ]);
}
elseif (is('/mypage')) {
  $user = current_user();

  if (empty($user)) {
    $_SESSION['notice'] = 'You must be logged in';

    return redirect('/');
  }
  else {
    return render('mypage.html.php', [
      'user' => $user,
      'last_login' => last_login()
    ]);
  }
}
elseif (is('/login')) {
  $result = attempt_login($_POST['login'], $_POST['password']);

  if (!empty($result['user'])) {
    session_regenerate_id(true);

    $_SESSION['user_id'] = $result['user']['id'];

    return redirect('/mypage');
  }
  else {
    switch($result['error']) {
    case 'locked':
      $_SESSION['notice'] = 'This account is locked.';
      break;
    case 'banned':
      $_SESSION['notice'] = "You're banned.";
      break;
    default:
      $_SESSION['notice'] = 'Wrong username or password';
      break;
    }

    return redirect('/');
  }
}
elseif (is('/report')) {
  echo json_encode([
    'banned_ips' => banned_ips(),
    'locked_users' => locked_users()
  ]);
}

