<?php


namespace Okay\Core;


use Okay\Core\Modules\Modules;
use Okay\Entities\TranslationsEntity;

class FrontTranslations
{
    /** @var array<string, string> */
    private $translations = [];

    private $_debugTranslation;
    private $_entityFactory;
    private $_languages;
    private $_modules;
    
    public function __construct(EntityFactory $entityFactory, Languages $languages, Modules $modules, $debugTranslation = false)
    {
        $this->_debugTranslation = (bool)$debugTranslation;
        $this->_entityFactory = $entityFactory;
        $this->_languages = $languages;
        $this->_modules = $modules;
        
        $this->init();
    }

    public function init()
    {
        $langLabel = $this->_languages->getLangLabel();

        /** @var TranslationsEntity $translations */
        $translations = $this->_entityFactory->get(TranslationsEntity::class);
        foreach ($translations->find(['lang' => $langLabel]) as $var => $translation) {
            $this->setTranslation($var, $translation->value);
        }
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

        // Если не нашли перевода на текущем языке, посмотрим может есть этот перевод на основном языке или уже на английском
        /** @var TranslationsEntity $translations */
        $translations = $this->_entityFactory->get(TranslationsEntity::class);
        $mainLanguage = $this->_languages->getMainLanguage();
        $res = $translations->get($var);
        if (isset($res->{'lang_' . $mainLanguage->label}) || isset($res->lang_en)) {
            if (isset($res->{'lang_' . $mainLanguage->label})) {
                $translation = $res->{'lang_' . $mainLanguage->label}->value;
            } else {
                $translation = $res->lang_en->value;
            }

            $this->setTranslation($var, $translation);

            // Если включили дебаг переводов, выведим соответствующее сообщение на неизвестный перевод
            if ($this->_debugTranslation === true) {
                $translation .= '<b style="color: red!important;">$lang->' . $var . ' from other language</b>';
            }
            return $translation;
        } elseif ($this->_debugTranslation === true) {
            return '<b style="color: red!important;">$lang->' . $var . ' not exists</b>';
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
        return $this->__get($var);
    }
    
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
