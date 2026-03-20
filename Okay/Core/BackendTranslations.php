<?php


namespace Okay\Core;


use Okay\Core\Modules\Modules;
use Psr\Log\LoggerInterface;

class BackendTranslations
{
    /** @var array<string, string> */
    private $translations = [];

    private $_logger;
    private $_modules;
    private $_initializedLang;
    private $_debugTranslation;
    private $_langEn;
    
    public function __construct(LoggerInterface $logger, Modules $modules, $debugTranslation = false)
    {
        $this->_logger = $logger;
        $this->_modules = $modules;
        $this->_debugTranslation = (bool)$debugTranslation;
    }
    
    public function getLangLabel()
    {
        return $this->_initializedLang;
    }
    
    public function initTranslations($langLabel = 'en')
    {
        if ($this->_initializedLang === $langLabel) {
            return;
        }

        $this->translations = [];

        // Перевод админки
        $lang = [];
        $file = "backend/lang/" .$langLabel . ".php";
        if (!file_exists($file)) {
            foreach (glob("backend/lang/??.php") as $f) {
                $file = "backend/lang/" . pathinfo($f, PATHINFO_FILENAME) . ".php";
                break;
            }
        }
        require_once($file);
        foreach ($lang as $var=>$translation) {
            $this->addTranslation($var, $translation);
        }

        foreach ($this->_modules->getRunningModules() as $runningModule) {
            foreach ($this->_modules->getModuleBackendTranslations($runningModule['vendor'], $runningModule['module_name'], $langLabel) as $var => $translation) {
                $this->addTranslation($var, $translation);
            }
        }

        $this->_initializedLang = $langLabel;
    }

    public function __get($var)
    {
        $var = $this->normalizeTranslationKey($var);
        if ($var === '') {
            return null;
        }

        if (array_key_exists($var, $this->translations)) {
            return $this->translations[$var];
        }

        if (empty($this->_langEn)) {
            $lang = [];
            require_once("backend/lang/en.php");
            $this->_langEn = $lang;
        }
        
        if (isset($this->_langEn[$var])) {
            $translation = $this->_langEn[$var];
            $this->setTranslation($var, $translation);

            // Если включили дебаг переводов, выведим соответствующее сообщение на неизвестный перевод
            if ($this->_debugTranslation === true) {
                $translation .= '<b style="color: red!important;">$btr->' . $var . ' from other language</b>';
            }
            return $translation;
        } elseif ($this->_debugTranslation === true) {
            return '<b style="color: red!important;">$btr->' . $var . ' not exists</b>';
        }

        return null;
    }

    public function __set($var, $translation)
    {
        $this->setTranslation($var, $translation);
    }

    public function __isset($var)
    {
        $var = $this->normalizeTranslationKey($var);
        return $var !== '' && array_key_exists($var, $this->translations);
    }
    
    public function getTranslation($var)
    {
        $translation = $this->__get($var);
        return is_object($translation) ? false : $translation;
    }

    /**
     * @param $var
     * @param $translation
     * добавление перевода к уже существующему набору
     */
    public function addTranslation($var, $translation)
    {
        $this->setTranslation($var, $translation);
    }

    private function setTranslation($var, $translation): void
    {
        $var = $this->normalizeTranslationKey($var);
        if ($var === '') {
            return;
        }

        $this->translations[$var] = $translation;
    }

    private function normalizeTranslationKey($var): string
    {
        return preg_replace('~[^\w]~', '', (string) $var) ?: '';
    }
}
