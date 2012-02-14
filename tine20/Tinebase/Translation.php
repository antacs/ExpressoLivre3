<?php
/**
 * Tine 2.0
 *
 * @package     Tinebase
 * @subpackage  Translation
 * @license     http://www.gnu.org/licenses/agpl.html AGPL3
 * @copyright   Copyright (c) 2008-2011 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 */

/**
 * primary class to handle translations
 *
 * @package     Tinebase
 * @subpackage  Translation
 */
class Tinebase_Translation
{
    /**
     * Lazy loading for {@see getCountryList()}
     * 
     * @var array
     */
    protected static $_countryLists = array();
    
    /**
     * List of officially supported languages
     *
     * @var array
     */
    private static $SUPPORTED_LANGS = array(
        #'bg',      // Bulgarian            Dimitrina Mileva <d.mileva@metaways.de>
        #'ca',      // Catalan              Damià Verger - JUG Països Catalans <dverger@joomla.cat>
        #'cs',      // Czech                Michael Sladek <msladek@brotel.cz>
        #'de',      // German               Cornelius Weiss <c.weiss@metaways.de>
        'en',      // English              Cornelius Weiss <c.weiss@metaways.de>
        'es',      // Spanish              Enrique Palacios <enriquepalaciosc@gmail.com>
        #'fr',      // French               Rémi Peltier <rpeltier@agglo-clermont.fr>
        #'hu',      // Hungarian            Gump <admin@kemenyfem.hu>
        #'it',      // Italian              Roberto Rossi <impiastro@gmail.com>
        #'ja',      // Japanese             Yuuki Kitamura <ykitamura@clasi-co.jp>
        #'nb',      // Norwegian Bokmål     Ronny Gonzales <gonzito@online.no>
        //'nl',      // Dutch                Joost Venema <post@joostvenema.nl>
        #'pl',      // Polish               Wojciech Kaczmarek <wojciech_kaczmarek@wp.pl>
        'pt_BR',      // Portuguese           Holger Rothemund <holger@rothemund.org>
        #'ru',      // Russian              Nikolay Parukhin <parukhin@gmail.com> 
        #'sv',      // Swedish              Andreas Storbjörk <andreas.storbjork@rambolo.net>
        #'zh_CN',   // Chinese Simplified   Jason Qi <qry@yahoo.com>
        #'zh_TW',   // Chinese Traditional  Frank Huang <frank.cchuang@gmail.com>
    );
    
    /**
     * returns list of all available translations
     * 
     * NOTE available are those, having a Tinebase translation
     * 
     * @return array list of all available translation
     *
     * @todo add test
     */
    public static function getAvailableTranslations()
    {
        $availableTranslations = array();
        
        if (TINE20_BUILDTYPE == 'RELEASE') {
            $list = self::$SUPPORTED_LANGS;
        } else {
            // look for po files in Tinebase
            $dirContents = scandir(dirname(__FILE__) . '/translations');
            sort($dirContents);
            $list = array();
            
            foreach ($dirContents as $poFile) {
                list ($localestring, $suffix) = explode('.', $poFile);
                if ($suffix == 'po') {
                    $list[] = $localestring;
                }
            }
        }
        
        foreach ($list as $localestring) {
            $locale = new Zend_Locale($localestring);
            $availableTranslations[] = array(
                'locale'   => $localestring,
                'language' => Zend_Locale::getTranslation($locale->getLanguage(), 'language', $locale),
                'region'   => Zend_Locale::getTranslation($locale->getRegion(), 'country', $locale)
            );
        }
            
        return $availableTranslations;
    }
    
    /**
     * get list of translated country names
     *
     * @return array list of countrys
     */
    public static function getCountryList()
    {
        $locale = Tinebase_Core::get('locale');
        $language = $locale->getLanguage();
        
        //try lazy loading of translated country list
        if (empty(self::$_countryLists[$language])) {
            $countries = Zend_Locale::getTranslationList('territory', $locale, 2);
            asort($countries);
            foreach($countries as $shortName => $translatedName) {
                $results[] = array(
                    'shortName'         => $shortName, 
                    'translatedName'    => $translatedName
                );
            }
    
            self::$_countryLists[$language] = $results;
        }

        return array('results' => self::$_countryLists[$language]);
    }
    
    /**
     * Get translated country name for a given ISO {@param $_regionCode}
     * 
     * @param String $regionCode [e.g. DE, US etc.]
     * @return String | null [e.g. Germany, United States etc.]
     */
    public static function getCountryNameByRegionCode($_regionCode)
    {
        $countries = self::getCountryList();
        foreach($countries['results'] as $country) {
            if ($country['shortName'] === $_regionCode) {
                return $country['translatedName'];
            }
        } 

        return null;
    }
    
    /**
     * Get translated country name for a given ISO {@param $_regionCode}
     * 
     * @param String $regionCode [e.g. DE, US etc.]
     * @return String | null [e.g. Germany, United States etc.]
     */
    public static function getRegionCodeByCountryName($_countryName)
    {
        $countries = self::getCountryList();
        foreach($countries['results'] as $country) {
            if ($country['translatedName'] === $_countryName) {
                return $country['shortName'];
            }
        } 

        return null;
    }
    
    /**
     * gets a supported locale
     *
     * @param   string $_localeString
     * @return  Zend_Locale
     * @throws  Tinebase_Exception_NotFound
     */
    public static function getLocale($_localeString = 'auto')
    {
        Zend_Locale::$compatibilityMode = false;
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " given localeString '$_localeString'");
        try {
            $locale = new Zend_Locale($_localeString);
            
            // check if we suppot the locale
            $supportedLocales = array();
            $availableTranslations = self::getAvailableTranslations();
            foreach ($availableTranslations as $translation) {
                $supportedLocales[] = $translation['locale'];
            }
            
            if (! in_array($_localeString, $supportedLocales)) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " '$locale' is not supported, checking fallback");
                
                // check if we find suiteable fallback
                $language = $locale->getLanguage();
                switch ($language) {
                    case 'zh':
                        $locale = new Zend_Locale('zh_CN');
                        break;
                    default: 
                        if (in_array($language, $supportedLocales)) {
                            $locale = new Zend_Locale($language);
                        } else {
                            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " no suiteable lang fallback found within this locales: " . print_r($supportedLocales, true) );
                            throw new Tinebase_Exception_NotFound('No suiteable lang fallback found.');
                        }
                        break;
                }
            }
        } catch (Exception $e) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " $e, falling back to locale en");
            $locale = new Zend_Locale('en');
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " selected locale: '$locale'");
        return $locale;
    }
    
    /**
     * get zend translate for an application
     * 
     * @param  string $_applicationName
     * @param  Zend_Locale $_locale [optional]
     * @return Zend_Translate
     * 
     * @todo return 'void' if locale = en
    */
    public static function getTranslation($_applicationName, Zend_Locale $_locale = NULL)
    {
        $locale = ($_locale !== NULL) ? $_locale : Tinebase_Core::get('locale');
        
        $cache = Tinebase_Core::getCache();
        
        $cacheId = 'getTranslation_' . (string)$locale . $_applicationName;
        
        // get translation from cache?
        if ($cache->test($cacheId)) {
            $translate = $cache->load($cacheId);
            
            return $translate;
        }
            
        // create new translation
        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . ucfirst($_applicationName) . DIRECTORY_SEPARATOR . 'translations';
        $translate = new Zend_Translate('gettext', $path, null, array(
            'scan' => Zend_Translate::LOCALE_FILENAME,
            'disableNotices' => TRUE,
        ));

        try {
            $translate->setLocale($locale);
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .' locale used: ' . $_applicationName . '/' . (string)$locale);
            
        } catch (Zend_Translate_Exception $e) {
            // the locale of the user is not available
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .' locale not found: ' . (string)$locale);
        }
            
        // store translation in cache
        $cache->save($translate, $cacheId, array('Zend_Translate'));
            
        return $translate;
    }
    
    /**
     * Returns collection of all javascript translations data for requested language
     * 
     * This is a javascript special function!
     * The data will be preseted to be included as javascript on client side!
     *
     * NOTE: This function is called from release.php cli script. In this case no 
     *       tine 2.0 core initialisation took place beforehand
     *       
     * @param  Zend_Locale $_locale
     * @return string      javascript
     */
    public static function getJsTranslations($_locale, $_appName = 'all')
    {
        $baseDir = dirname(__FILE__) . "/..";
        $localeString = (string) $_locale;
        
        $genericTranslationFile = "$baseDir/Tinebase/js/Locale/static/generic-$localeString.js";
        $extjsTranslationFile   = "$baseDir/library/ExtJS/src/locale/ext-lang-$localeString.js";
        $tine20TranslationFiels = self::getPoTranslationFiles($_locale);
        
        $allTranslationFiles    = array_merge(array($genericTranslationFile, $extjsTranslationFile), $tine20TranslationFiels);
        
        $jsTranslations = NULL;
        
        if (Tinebase_Core::get(Tinebase_Core::CACHE) && $_appName == 'all') {
            // setup cache (saves about 20% @2010/01/28)
            $cache = new Zend_Cache_Frontend_File(array(
                'master_files' => $allTranslationFiles
            ));
            $cache->setBackend(Tinebase_Core::get(Tinebase_Core::CACHE)->getBackend());
            
            $cacheId = __CLASS__ . "_". __FUNCTION__ . "_{$localeString}";
            
            $jsTranslations = $cache->load($cacheId);
        }
        
        if (! $jsTranslations) {
            $jsTranslations  = "";
            
            if (in_array($_appName, array('Tinebase', 'all'))) {
                $jsTranslations .= "/************************** generic translations **************************/ \n";
                $jsTranslations .= file_get_contents("$baseDir/Tinebase/js/Locale/static/generic-$localeString.js");
                
                $jsTranslations  .= "/*************************** extjs translations ***************************/ \n";
                if (file_exists("$baseDir/library/ExtJS/src/locale/ext-lang-$localeString.js")) {
                    $jsTranslations  .= file_get_contents("$baseDir/library/ExtJS/src/locale/ext-lang-$localeString.js");
                } else {
                    $jsTranslations  .= "console.error('Translation Error: extjs changed their lang file name again ;-(');";
                }
            }
            
            $poFiles = self::getPoTranslationFiles($_locale);
            foreach ($poFiles as $appName => $poPath) {
                if ($_appName !='all' && $_appName != $appName) continue;
                $poObject = self::po2jsObject($poPath);
                $jsTranslations  .= "/********************** tine translations of $appName**********************/ \n";
                $jsTranslations .= "Locale.Gettext.prototype._msgs['./LC_MESSAGES/$appName'] = new Locale.Gettext.PO($poObject); \n";
            }
            
            if (isset($cache)) {
                $cache->save($jsTranslations, $cacheId);
            }
        }
        
        return $jsTranslations;
    }
    
    /**
     * gets array of lang dirs from all applications having translations
     * 
     * Note: This functions must not query the database! 
     *       It's only used in the development and release building process
     * 
     * @return array appName => translationDir
     */
    public static function getTranslationDirs()
    {
        $tine20path = dirname(__File__) . "/..";
        
        $langDirs = array();
        $d = dir($tine20path);
        while (false !== ($appName = $d->read())) {
            $appPath = "$tine20path/$appName";
            if ($appName{0} != '.' && is_dir($appPath)) {
                $translationPath = "$appPath/translations";
                if (is_dir($translationPath)) {
                    $langDirs[$appName] = $translationPath;
                }
            }
        }
        return $langDirs;
    }
    
    /**
     * gets all available po files for a given locale
     *
     * @param  Zend_Locale $_locale
     * @return array appName => pofile path
     */
    public static function getPoTranslationFiles($_locale)
    {
        $localeString = (string)$_locale;
        $poFiles = array();
        
        $translationDirs = self::getTranslationDirs();
        foreach ($translationDirs as $appName => $translationDir) {
            $poPath = "$translationDir/$localeString.po";
            if (file_exists($poPath)) {
                $poFiles[$appName] = $poPath;
            }
        }
        
        return $poFiles;
    }
    
    /**
     * convertes po file to js object
     * 
     * @todo rewrite this in a way that we can automatically add singulars
     *       seperatly into the js output
     *
     * @param  string $filePath
     * @return string
     */
    public static function po2jsObject($filePath)
    {
        $po = file_get_contents($filePath);
        
        global $first, $plural;
        $first = true; 
        $plural = false;
        
        $po = preg_replace('/\r?\n/', "\n", $po);
        $po = preg_replace('/#.*\n/', '', $po);
        // 2008-08-25 \s -> \n as there are situations when whitespace like space breaks the thing!
        $po = preg_replace('/"(\n+)"/', '', $po);
        $po = preg_replace('/msgid "(.*?)"\nmsgid_plural "(.*?)"/', 'msgid "$1, $2"', $po);
        $po = preg_replace_callback('/msg(\S+) /', create_function('$matches','
            global $first, $plural;
            switch ($matches[1]) {
                case "id":
                    if ($first) {
                        $first = false;
                        return "";
                    }
                    if ($plural) {
                        $plural = false;
                        return "]\n, ";
                    }
                    return ", ";
                case "str":
                    return ": ";
                case "str[0]":
                    $plural = true;
                    return ": [\n  ";
                default:
                    return " ,";
            }
        '), $po);
        $po = "({\n" . (string)$po . ($plural ? "]\n})" : "\n})");
        return $po;
    }
    
    /**
     * convert date to string
     * 
     * @param Tinebase_DateTime $_date [optional]
     * @param string            $_timezone [optional]
     * @param Zend_Locale       $_locale [optional]
     * @param string            $_part one of date, time or datetime
     * @return string
     */
    public static function dateToStringInTzAndLocaleFormat(DateTime $_date = NULL, $_timezone = NULL, Zend_Locale $_locale = NULL, $_part = 'datetime')
    {
        $date = ($_date !== NULL) ? clone($_date) : Tinebase_DateTime::now();
        $timezone = ($_timezone !== NULL) ? $_timezone : Tinebase_Core::get(Tinebase_Core::USERTIMEZONE);
        $locale = ($_locale !== NULL) ? $_locale : Tinebase_Core::get(Tinebase_Core::LOCALE);
        
        $date = new Zend_Date($date->getTimestamp());
        $date->setTimezone($timezone);
        
        $dateString = $date->toString(Zend_Locale_Format::getDateFormat($locale), $locale);
        $timeString = $date->toString(Zend_Locale_Format::getTimeFormat($locale), $locale);
        
        switch($_part) {
            case 'date': return $dateString;
            case 'time': return $timeString;
            default: return $dateString . ' ' . $timeString;
        }
    }
}
