<?php

/**
 * Contao Open Source CMS
 */

namespace sioweb\contao\extensions\vnp;
use Contao;

/**
 * @file ContentVNPricing.php
 * @class ContentVNPricing
 * @author Sascha Weidner
 * @version 3.0.0
 * @package sioweb.contao.extensions.vnp
 * @copyright Sascha Weidner, Sioweb
 */

class ContentVNPricing extends \Module {

    /**
     * URL cache array
     * @var array
     */
    private static $arrUrlCache = array();

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'vnp_default';


    /**
     * Display a wildcard in the back end
     *
     * @return string
     */
    public function generate() {
        if (TL_MODE == 'BE') {
            /** @var \BackendTemplate|object $objTemplate */
            $objTemplate = new \BackendTemplate('be_wildcard');

            $objTemplate->wildcard = '### Version & Pricing ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

            return $objTemplate->parse();
        }

        if(empty($this->vnp_product)) return;

        return parent::generate();
    }


    /**
     * Generate the module
     */
    protected function compile() {
        global $objPage;

        $objProduct = \VnpProductsModel::findByPk($this->vnp_product);
        $objVersions = $objProduct->getRelated('id');

        $attributes = deserialize($this->vnp_attributes);
        $objAttributes = $this->Database->execute("SELECT * FROM tl_vnp_attributes WHERE id IN ('".implode("','",$attributes)."') ORDER BY FIND_IN_SET(id,'".implode(',',$attributes)."')");

        $arrAttributeDisclaimers = array();
        while($objVersions->next()) {

            if(!empty($objVersions->source) && $objVersions->source !== 'default') {
                $objVersions->url = $this->generateUrl($objVersions);
            }

            $objVersions->paymentType = $objVersions->getRelated('paymentType');

            $arrAttributeDisclaimers[$objVersions->id] = array(
                'version' => $objVersions->version,
                'disclaimers' => array()
            );

            $arrVersions[$objVersions->id] = $objVersions->row();

        }

        $AttributeDisclaimers = \VnpVersionAttributesModel::findBy(array("tl_vnp_version_attributes.pid IN('".implode("','",array_keys($arrVersions))."')"),array());
        
        $disclaimerCounter = 0;
        if(!empty($AttributeDisclaimers)) {
            while($AttributeDisclaimers->next()) {
                $AttributeDisclaimers->disclaimer = deserialize($AttributeDisclaimers->disclaimer);
                $arrAttributeDisclaimers[$AttributeDisclaimers->pid]['disclaimers'][$AttributeDisclaimers->attribute] = $AttributeDisclaimers->disclaimer;
            }
        }

        foreach($arrAttributeDisclaimers as $versionId => $attributes) {

            if(empty($attributes['disclaimers'])) {
                continue;
            }

            $_disclaimers = array();
            foreach($attributes['disclaimers'] as $k1 => $disclaimers) {
                foreach($disclaimers as $disclaimer) {
                    $_disclaimers[$k1][++$disclaimerCounter] = $disclaimer;
                }
            }
            $arrAttributeDisclaimers[$versionId]['disclaimers'] = $_disclaimers;
            unset($_disclaimers);
        }

        $VersionPrices = \VnpVersionPricesModel::findBy(array("tl_vnp_version_prices.pid IN('".implode("','",array_keys($arrVersions))."')"),array());
        $arrVersionPrices = array();
        if(!empty($VersionPrices)) {
            while($VersionPrices->next()) {
                $arrVersionPrices[$VersionPrices->pid][] = $VersionPrices->row();
            }
        }


        $paymentDisclaimer = array();
        foreach($arrVersions as $versionId => &$version) {

            if(empty($arrAttributeDisclaimers[$versionId]['disclaimers'])) {
                unset($arrAttributeDisclaimers[$versionId]);
            }

            $version['attributes'] = deserialize($version['attributes']);
            $version['optional_attributes'] = deserialize($version['optional_attributes']);
            
            if(!empty($version['paymentType']->description)) {

                if(!in_array($version['paymentType']->id,$paymentDisclaimer)) {
                    $paymentDisclaimer[] = $version['paymentType']->id;
                    $version['paymentType'] = array(
                        'title' => $version['paymentType']->headline,
                        'description' => $version['paymentType']->description,
                        'disclaimer' => ++$disclaimerCounter
                    );

                    if(empty($arrAttributeDisclaimers[9999])) {
                        $arrAttributeDisclaimers[9999] = array(
                            'version' => $version['paymentType']['title'],
                            'disclaimers' => array()
                        );
                    }
                    $arrAttributeDisclaimers[9999]['disclaimers'][0][$version['paymentType']['disclaimer']] =  $version['paymentType']['description'];
                } else {
                    $version['paymentType'] = array(
                        'title' => $version['paymentType']->headline,
                        'description' => $version['paymentType']->description,
                        'disclaimer' => $disclaimerCounter
                    );
                }
            } else {
                $version['paymentType'] = array(
                    'title' => $version['paymentType']->headline
                );
            }
            
            if(!empty($version['status'])) {
                $status = array();
                foreach(deserialize($version['status']) as $statusKey => $statusValue) {
                    $status[] = 'vnp_status_'.standardize($statusValue);
                }
                $version['status'] = implode(' ',$status);
            } else {
                $version['status'] = 'vnp_status_default';
            }

            if(empty($version['attributes'])) {
                $version['attributes'] = array();
            }

            if(empty($version['optional_attributes'])) {
                $version['optional_attributes'] = array();
            }
        }

        // die();

        unset($version);

        $sortVersions = deserialize($this->vnp_versions);
        $_arrVersions = array();
        foreach($sortVersions as $key => $id) {
            $_arrVersions[$id] = $arrVersions[$id];
        }
        $arrVersions = $_arrVersions;
        unset($_arrVersions);

        $this->Template->attributes = $objAttributes->fetchAllAssoc();
        $this->Template->attribute_disclaimers = $arrAttributeDisclaimers;
        $this->Template->versions = $arrVersions;
        $this->Template->disclaimers = deserialize($this->vnp_disclaimer);
    }


    /**
     * Generate a URL and return it as string
     *
     * @param \NewsModel $objItem
     * @param boolean    $blnAddArchive
     *
     * @return string
     */
    protected function generateUrl($objItem, $blnAddArchive=false)
    {
        $strCacheKey = 'id_' . $objItem->id;

        // Load the URL from cache
        if (isset(self::$arrUrlCache[$strCacheKey]))
        {
            return self::$arrUrlCache[$strCacheKey];
        }

        // Initialize the cache
        self::$arrUrlCache[$strCacheKey] = null;

        switch ($objItem->source)
        {
            // Link to an external page
            case 'external':
                if (substr($objItem->url, 0, 7) == 'mailto:')
                {
                    self::$arrUrlCache[$strCacheKey] = \StringUtil::encodeEmail($objItem->url);
                }
                else
                {
                    self::$arrUrlCache[$strCacheKey] = ampersand($objItem->url);
                }
                break;

            // Link to an internal page
            case 'internal':
                if (($objTarget = $objItem->getRelated('jumpTo')) !== null)
                {
                    /** @var \PageModel $objTarget */
                    self::$arrUrlCache[$strCacheKey] = ampersand($objTarget->getFrontendUrl());
                }
                break;

            // Link to an article
            case 'article':
                if (($objArticle = \ArticleModel::findByPk($objItem->articleId, array('eager'=>true))) !== null && ($objPid = $objArticle->getRelated('pid')) !== null)
                {
                    /** @var \PageModel $objPid */
                    self::$arrUrlCache[$strCacheKey] = ampersand($objPid->getFrontendUrl('/articles/' . ((!\Config::get('disableAlias') && $objArticle->alias != '') ? $objArticle->alias : $objArticle->id)));
                }
                break;
        }

        // Link to the default page
        if (self::$arrUrlCache[$strCacheKey] === null)
        {
            $objPage = \PageModel::findWithDetails($objItem->jumpTo);

            if ($objPage === null)
            {
                self::$arrUrlCache[$strCacheKey] = ampersand(\Environment::get('request'), true);
            }
            else
            {
                self::$arrUrlCache[$strCacheKey] = ampersand($objPage->getFrontendUrl(((\Config::get('useAutoItem') && !\Config::get('disableAlias')) ? '/' : '/items/') . ((!\Config::get('disableAlias') && $objItem->alias != '') ? $objItem->alias : $objItem->id)));
            }

            // Add the current archive parameter (news archive)
            if ($blnAddArchive && \Input::get('month') != '')
            {
                self::$arrUrlCache[$strCacheKey] .= (\Config::get('disableAlias') ? '&amp;' : '?') . 'month=' . \Input::get('month');
            }
        }

        return self::$arrUrlCache[$strCacheKey];
    }
}
