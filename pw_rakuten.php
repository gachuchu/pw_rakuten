<?php /*@charset "utf-8"*/
/*********************************************************************
 Plugin Name:   PW_Rakuten
 Plugin URI:    http://syncroot.com/
 Description:   楽天商品リンクを楽天APIで取得したデータでそれなりに置き換えるプラグインです。
 Author:        gachuchu
 Version:       1.0.0
 Author URI:    http://syncroot.com/
 *********************************************************************/

/*********************************************************************
 Copyright 2010 gachuchu  (email : syncroot.com@gmail.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *********************************************************************/

require_once(WP_PLUGIN_DIR . "/libpw/libpw.php");
require(dirname(__FILE__) . '/libpw_rakuten_api.php');

if(!class_exists('PW_Rakuten')){
    /**
     *********************************************************************
     * 本体
     *********************************************************************/
    class PW_Rakuten extends libpw_Plugin_Substance {
        //---------------------------------------------------------------------
        const UNIQUE_KEY = 'PW_Rakuten';
        const CLASS_NAME = 'PW_Rakuten';

        //---------------------------------------------------------------------
        private $opt;
        private $api;

        /**
         *====================================================================
         * 初期化
         *===================================================================*/
        public function init() {
            // オプション設定
            $this->opt = new libpw_Plugin_DataStore($this->unique . '_OPT',
                                                    array(
                                                        libpw_Rakuten_API::APP_ID       => '',
                                                        libpw_Rakuten_API::AFFILIATE_ID => '',
                                                        )
                                                    );

            // api作成
            $this->opt->load();
            $api_opt = $this->opt->getAll();
            $api_opt[libpw_Rakuten_API::LOCKFILE]  = dirname(__FILE__) . '/lock/lockfile';
            $api_opt[libpw_Rakuten_API::CACHE_DIR] = dirname(__FILE__) . '/cache/';
            $this->api = new libpw_Rakuten_API($api_opt);
            $this->opt->clear();

            // 管理メニュー
            $this->addMenu($this->unique . 'の設定ページ',
                           $this->unique);

            // 変換処理
            add_filter('the_content', array(&$this, 'execute'));
        }

        /**
         *====================================================================
         * deactivate
         *===================================================================*/
        public function deactivate() {
            $this->opt->delete();
        }

        static public function uninstall() {
            $ref = self::getInstance(self::CLASS_NAME);
            $ref->opt->delete();
        }

        /**
         *====================================================================
         * rakutenリンクの変換
         *===================================================================*/
        public function execute($content) {
            // 可能性があるかどうかをチェック
            if((strpos($content, 'afl.rakuten') === false)){
                return $content;
            }

            $this->opt->load();

            // 画像とテキスト(PC) のみ対応
            $regdomain = 'https?:\/\/(?:www\.|rcm\.|rcm-jp\.)?(?P<domain>(?:rakuten\.(?:ca|de|fr|(?:co\.)?jp|co\.uk|com)|javari\.jp))\/';
            $regcode   = 'm=http(?:%3a%2f%2f|%3A%3A%2F)m\.rakuten\.co\.jp(?:%2f|%2F)(?P<shop_id>.*?)(?:%2f|%2F)i(?:%2f|%2F)(?P<item_id>\d+)(?:%2f|%2F|")';
            $regblock  = '<table[^>]*?>.*?<a href="[^"]*' . $regcode . '[^>]*?.*?<\/table>';
            $regimg    = '/(?P<img><a[^>]*?>[^<]*?<img[^>]*?>.*?<\/a>)/s';
            $chkignore = 'data-pw-rakuten-ignore="true"';

            $regexps   = array();
            $regexps[] = "/{$regblock}/s";

            foreach($regexps as $regexp){
                if($num = preg_match_all($regexp, $content, $res)){
                    for($i = 0; $i < $num; ++$i){
                        if(strpos($res[0][$i], $chkignore)){
                            continue;
                        }
                        $tmp = array();
                        $tmp['shop_id'] = $res['shop_id'][$i];
                        $tmp['item_id'] = $res['item_id'][$i];
                        if(preg_match($regimg, $res[0][$i], $ires)){
                            $ires['img'] = preg_replace('/(?:_ex(?:%3d|%3D)\d+?x\d+)/', '_ex%3d400x400', $ires['img']);
                            $tmp['img'] = $ires['img'];
                        }
                        $xml = $this->api->itemLookup($tmp['shop_id'],
                                                      $tmp['item_id']
                                                      );
                        $rep_str = $this->get_replace_str($xml, $tmp);
                        if($rep_str){
                            $content = str_replace($res[0][$i], $rep_str, $content);
                        }
                    }
                }
            }

            $this->opt->clear();

            return $content;
        }

        /**
         *====================================================================
         * render
         *===================================================================*/
        public function render() {
            $this->opt->load();

            $this->renderStart($this->unique . 'の設定項目');
            if($this->request->isUpdate()){
                $this->renderUpdate('<p>設定を更新しました </p>');
                $this->opt->update($this->request->getAll());
                $this->opt->save();
            }

            $this->renderTableStart();

            //---------------------------------------------------------------------
            $this->renderTableLine();

            // アプリケーション)ID
            $val = $this->opt->get(libpw_Rakuten_API::APP_ID);
            $this->renderTableNode(
                'アプリID/デベロッパーID',
                '<input type="text" size="60" name="' . libpw_Rakuten_API::APP_ID . '" value="' . $val . '" />'
                );

            // アフィリエイトID
            $val = $this->opt->get(libpw_Rakuten_API::AFFILIATE_ID);
            $this->renderTableNode(
                'アフィリエイトID',
                '<input type="text" size="60" name="' . libpw_Rakuten_API::AFFILIATE_ID . '" value="' . $val . '" />'
                );

            //---------------------------------------------------------------------
            $this->renderTableEnd();

            $this->renderSubmit('変更を保存');
            $this->renderEnd();

            $this->opt->clear();
        }

        /**
         *--------------------------------------------------------------------
         * 画像取得
         *-------------------------------------------------------------------*/
        private function get_image_info(&$item) {
            $size = array('mediumImageUrls',
                          'smallImageUrls',
                          );
            foreach($size as $s){
                if($item->$s){
                    foreach($item->$s->imageUrl as $img){
                        return $img;
                    }
                }
            }
            return null;
        }

        /**
         *--------------------------------------------------------------------
         * 結果出力
         *-------------------------------------------------------------------*/
        private function get_replace_str(&$xml, &$reg) {
            // 必要な情報の収集
            if($xml->count != 1){
                return false;
            }

            $item = $xml->Items->Item;
            if(!$xml || !$item){
                return false;
            }

            if(!empty($item->affiliateUrl)){
                $url = $item->affiliateUrl;
            }else{
                $url = $item->itemUrl;
            }
            if(!$url){
                return false;
            }
            
            $name  = $item->itemName;
            $price = $item->itemPrice;

            if(isset($reg['img'])){
                $img = $reg['img'];
            }else{
                $imgurl = $this->get_image_info($item);
                $img = "<a href=\"{$url}\"><img src=\"{$imgurl}\" /></a>";
            }

            // dt部
            if(!$img){
                $dt = '<dt class="noimage">no image</dt>';
            }else{
                $dt  = "<dt>{$img}</dt>";
            }

            // dd部
            $dd = '<dd><ul>';
            $dd .= "<li><a href=\"{$url}\">{$name}</a></li>";

            /*
            if(!empty($item->catchcopy)){
                $dd .= "<li>{$item->catchcopy}</li>";
            }else if(!empty($item->itemCaption)){
                $dd .= "<li>{$item->itemCaption}</li>";
            }
             */

            // 価格表示
            $tax     = ($item->taxFlag == 0) ? '税込' : '税別';
            $postage = ($item->postageFlag == 0) ? '送料込' : '送料別';
            $dd .= '<li class="price">価格:';
            $dd .= "<span>{$price}円</span> ({$tax}, {$postage})";
            $dd .= '</li>';
            $dd .= '</ul>';

            $dd .= '</dd>';

            // 返却情報を作成
            return "<dl class=\"rakuten ad\">{$dt}{$dd}</dl>";
        }
    }


    /**
     *********************************************************************
     * 初期化
     *********************************************************************/
    PW_Rakuten::create(PW_Rakuten::UNIQUE_KEY,
                       PW_Rakuten::CLASS_NAME,
                       __FILE__);

}
