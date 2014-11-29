## ISUCON4 Quals

[エンジニア向け pixiv開発のbugリストからの脱出！エンジニア職インターン](https://ssl.pixiv.net/recruit/entry/winter_intern.php) へ応募します。

## 行ったこと

[公式リポジトリの README.md](https://github.com/pixiv/intern2014w/blob/master/README.md) を引用しながら示します。

[DONE] ISUCON4 予選問題の AMI を ISUCON4 予選問題の規定通りに立ち上げてください  

[DONE] benchmarker を動かして正しく動いているか確認してください  

* デフォルトの ruby 実装にてスコア 1182 が出ているのを確認しました

[DONE] デフォルトは ruby 実装が動いているので PHP 実装に切り替えてください  

[DONE] benchmarker を動かして正しく動いているか確認してください  

* デフォルトの PHP 実装にてスコア 1638 が出ているのを確認しました

[DONE] ブラウザから表示されるか確認して下さい  

[DONE] `/home/isucon/webapp` ディレクトリを `git init` して，先程作ったあなたのリポジトリにコミットしてください  

[DONE] PHP のソースコードに一つ以上の変更を加えてスコアを上げてください  

* 変更を加えた点、および実装の理由などは後述します。
* スコアは 1789 となり、100 強上がりました。
  
[DONE] （任意課題）Nginx や MySQL などのパラメータなどを変更して，スコアを上げてください

* 変更を加えた点、および実装の理由などは後述します。

## PHP のソースコード変更箇所

課題として「PHP のソースコードに一つ以上の変更を加えてスコアを上げてください」とありましたが、多少の変更ではスコアアップにはつながらないと思い、大きな変更が必要だと考えました。  
今回の対象アプリケーションは非常にシンプルな構造になっており、Limonade  を利用するほどのものではないと判断しました。そこで、Limonade を削除し、Limonade に依存している部分を書き直すことにしました。  

結果としてスコアは 1789 となり、初期実装と比べて 100 強程度上昇しました。

## 変更しなかった箇所

初期実装の `index.php` の `banned_ips()` において、  

```
foreach ($last_succeeds as $row) {
  $stmt = $db->prepare('SELECT COUNT(1) AS cnt FROM login_log WHERE ip = :ip AND :id < id');
  /* ... */
}
```

という処理がありました。`foreach` 内で `$db->prepare()` を呼んでおり、非常に無駄だと思いましたが、この関数が呼ばれるのは `/report` へアクセスしたときのみで、1分以内にレスポンスを返すことができればスコアには影響がないので変更しませんでした。

## 任意課題

PHP ソースコードの変更ではなく、パラメータ調整などで行った変更です。

### インデックスを張る

ログイン試行時に `ip_banned()`, `user_locked()` が呼ばれており、これらの実行に時間がかかっていました。  
よって、`init.sh` に次のコマンドを追加し、インデックスを張りました。

```
mysql -h ${myhost} -P ${myport} -u ${myuser} ${mydb} <<< "ALTER TABLE login_log ADD INDEX ip (ip), ADD INDEX user_id (user_id);"
```

### カーネルチューニング

`benchmarker` の実行中に `cannot assign requested address` というエラーが多くみられるようになりました。  
調べたところ、ローカルポートの割り当て不足であることが分かったので、`/etc/sysctl.conf` に

```
net.ipv4.ip_local_port_range = 10000 65000
net.ipv4.tcp_tw_recycle = 1
```

を追加しました。

### workload の変更

workload 数を増やし、スコアが最も高くなる workload にてスコアを取ることにしました。  

## 結果

初期実装（PHP）：スコア 1638  
PHP ファイルを変更した実装：スコア 1789  
任意課題後の実装：スコア 38117 (workload=16)  
  
となりました。

