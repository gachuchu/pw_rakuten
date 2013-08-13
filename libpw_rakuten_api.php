<?php /*@charset "utf-8"*/
/**
 *********************************************************************
 * RakutenAPI
 * @file   pw_rakuten_api.php
 * @date   2013-03-12 20:48:43 (Tuesday)
 *********************************************************************/

require(dirname(__FILE__) . '/libpw_rakuten_critical_section.php');
require(dirname(__FILE__) . '/libpw_rakuten_cache.php');

/**
 *====================================================================
 * 本体
 *===================================================================*/
if(!class_exists('libpw_Rakuten_API')){
    class libpw_Rakuten_API {
        //---------------------------------------------------------------------
        const VERSION        = '2013-08-05';
        const REQUEST_WAIT   = 2.0;
        const CACHE_LIFETIME = 3600;

        //---------------------------------------------------------------------
        const APP_ID       = 'applicationId';
        const AFFILIATE_ID = 'affiliateId';
        const LOCKFILE     = 'lockfile';
        const CACHE_DIR    = 'cache_dir';

        //---------------------------------------------------------------------
        const LOCKFILE_DEFAULT  = 'pwamzapi_lockfile';
        const CACHE_DIR_DEFAULT = '/pwamzapi_cache/';

        //---------------------------------------------------------------------
        const ENDPOINT = 'https://app.rakuten.co.jp/services/api/IchibaItem/Search/';

        //---------------------------------------------------------------------
        private $opt;
        private $critical;
        private $cache;

        /**
         *====================================================================
         * construct
         *===================================================================*/
        public function __construct($opt = array()) {
            $this->opt = wp_parse_args($opt,
                                       array(libpw_Rakuten_API::APP_ID          => '',
                                             libpw_Rakuten_API::AFFILIATE_ID    => '',
                                             libpw_Rakuten_API::LOCKFILE        => libpw_Rakuten_API::LOCKFILE_DEFAULT,
                                             libpw_Rakuten_API::CACHE_DIR       => libpw_Rakuten_API::CACHE_DIR_DEFAULT,
                                             )
                                       );

            $this->critical = new libpw_Rakuten_Critical_Section($this->opt[libpw_Rakuten_API::LOCKFILE]);
            $this->cache    = new libpw_Rakuten_Cache(self::CACHE_LIFETIME, $this->opt[libpw_Rakuten_API::CACHE_DIR]);
        }

        /**
         *--------------------------------------------------------------------
         * リクエスト実行
         *-------------------------------------------------------------------*/
        private function request($query, $cache_key) {
            // endpoint決定
            $endpoint = self::ENDPOINT . str_replace('-', '', self::VERSION);

            // リクエストの作成
            $request = $endpoint . '?' . $query;

            // データ取得
            $wait = self::REQUEST_WAIT;
            $xml  = false;

            // クリティカルセクションで挟む
            if($this->critical->start($wait)){
                $curl = curl_init($request);
                curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_AUTOREFERER, true);
                $xml = curl_exec($curl);
                curl_close($curl);
                //$xml = @file_get_contents($request);
                if($xml !== false){
                    $this->cache->set($cache_key, $xml);
                }
                $this->critical->end($wait);
            }

            return $xml;
        }

        /**
         *====================================================================
         * ItemLookup
         *===================================================================*/
        public function itemLookup($shop_id, $item_id, $xml_error_handling = true) {
            $cache_key = $shop_id . '_' . $item_id;
            $result    = false;
            $xml       = false;

            if($xml = $this->cache->get($cache_key)){
                // キャッシュがあったのでそれを利用
            }else if(($this->opt[self::APP_ID] != '')){
                // キャッシュが無かったのでAPIを利用して情報取得
                $param = array();
                $param['format']        = 'xml';
                $param['applicationId'] = $this->opt[self::APP_ID];
                $param['itemCode']      = "{$shop_id}:{$item_id}";
                if($this->opt[self::AFFILIATE_ID] != ''){
                    $param['affiliateId'] = $this->opt[self::AFFILIATE_ID];
                }
                // クエリ作成
                $q = '';
                foreach($param as $key => $val){
                    $q .= "&{$key}={$val}";
                }
                $q = substr($q, 1); // 先頭の&を取り除く

                // リクエスト発行
                $xml = $this->request($q, $cache_key);
            }else{
                $xml = false;
            }

            if($xml != false){
                $xml = simplexml_load_string($xml);
            }

            return $xml;
        }
    }
}
