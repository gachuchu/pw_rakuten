<?php /*@charset "utf-8"*/
/**
 *********************************************************************
 * クリティカルセクション管理
 * @file   libpw_critical_section.php
 * @date   2013-03-09 01:44:43 (Saturday)
 *********************************************************************/

/**
 *====================================================================
 * 本体
 *===================================================================*/
if(!class_exists('libpw_Rakuten_Critical_Section')){
    class libpw_Rakuten_Critical_Section {
        private $lockfile;
        private $handle;
        private $is_new;

        /**
         *====================================================================
         * construct
         *===================================================================*/
        public function __construct($lockfile = './libpw_lockfile') {
            $this->lockfile = $lockfile;
            $this->handle   = false;
            $this->is_new   = false;
        }

        /**
         *====================================================================
         * クリティカルセクション開始
         *===================================================================*/
        public function start($margin = 0.0) {
            if($this->handle){
                return true;
            }

            $is_new = false;

            // 読み書きでオープン
            if(!($fh = @fopen($this->lockfile, 'r+'))){
                // 無いみたいなので新規で開こうとしてみる
                // 'x' 書き込みのみでオープンします。ファイルポインタをファイルの先頭に置きます。
                // ファイルが既に存在する場合には fopen() は失敗し、 E_WARNING レベルのエラーを発行します。
                // ファイルが存在しない場合には新規作成を試みます。
                // これは open(2) システムコールにおける O_EXCL|O_CREAT フラグの指定と等価です。
                // このオプションはPHP4.3.2以降でサポートされ、また、 ローカルファイルに対してのみ有効です。
                // 'x+' 読み込み／書き出し用でオープンします。 それ以外のふるまいは 'x' と同じです。
                if(!($fh = @fopen($this->lockfile, 'x+'))){
                    // 新規で作れなかったのでもう一度だけ読み書きでオープンを試す
                    if(!($fh = @fopen($this->lockfile, 'r+'))){
                        return false;
                    }
                }else{
                    $is_new = true;
                }
            }

            // 排他的ロック
            if(!flock($fh, LOCK_EX)){
                // ロック失敗したのであきらめる
                if($is_new){
                    unlink($this->lockfile); // 新規作成の時はゴミファイルになるので削除する
                }
                fclose($fh);
                return false;
            }

            // ウェイトする時間を計算
            $wait = 0.0;

            if(!$is_new){
                // ファイルが存在した場合は既存のロック状況を確認する
                $last_stat = '';
                while(!feof($fh)){
                    $last_stat .= fread($fh, 8192);
                }
                $last_stat = unserialize($last_stat);
                if(($last_stat == false) || !is_array($last_stat)){
                    // ロック状況がおかしかったのであきらめる
                    unlink($this->lockfile);
                    flock($fh, LOCK_UN);
                    fclose($fh);
                    return false;
                }

                // ロック状況の確認
                if($last_stat[2]){
                    // ロックされていたので自分の決めた時間待つ
                    $wait = $margin;
                }else if($last_stat[1] > 0){
                    // 待機指定があったのでその時間は待つ
                    $wait = $last_stat[1] - (microtime(true) - $last_stat[0]);
                }
            }

            // 必要な場合ウェイト
            if($wait > 0.0){
                usleep((int)($wait * 1000000));
            }

            // ロック状況の更新
            if(!$this->update_stat($fh, 0.0, true)){
                // 更新に失敗したのであきらめる
                if($is_new){
                    unlink($this->lockfile); // 新規作成の時はゴミファイルになるので削除する
                }
                flock($fh, LOCK_UN);
                fclose($fh);
                return false;
            }

            $this->handle = $fh;
            $this->is_new = $is_new;

            return true;
        }

        /**
         *====================================================================
         * クリティカルセクション終了
         *===================================================================*/
        public function end($interval = 0.0) {
            if(!$this->handle){
                return true;
            }

            if(!$this->update_stat($this->handle, $interval, false)){
                if($this->is_new){
                    unlink($this->lockfile);
                }
                flock($this->handle, LOCK_UN);
                fclose($this->handle);
                $this->handle = false;
                $this->is_new = false;
                return false;
            }

            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = false;
            $this->is_new = false;
            return true;
        }

        /**
         *--------------------------------------------------------------------
         * ロック状況の更新
         *-------------------------------------------------------------------*/
        private function update_stat($fh, $interval, $is_lock = false) {
            // ファイルを空にする
            if(!ftruncate($fh, 0)){
                return false;
            }

            // ファイルポインタを先頭に移動
            if(fseek($fh, 0, SEEK_SET) != 0){
                return false;
            }

            // 更新状況の書き込み
            // [0]      開始時間
            // [1]      後続への待機指定
            // [2]      ロック状況
            $stat = array(microtime(true), $interval, $is_lock);
            if(fwrite($fh, serialize($stat)) === false){
                return false;
            }

            if(!fflush($fh)){
                return false;
            }

            return true;
        }
    }
}
