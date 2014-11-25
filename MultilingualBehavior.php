<?php

namespace claudejanz\multilingual;

use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\validators\Validator;

class MultilingualBehavior extends Behavior {

    /**
     * Multilingual attributes
     * @var array
     */
    public $attributes;

    /**
     * Available languages
     * It can be a simple array: array('fr', 'en') or an associative array: array('fr' => 'Français', 'en' => 'English')
     * For associative arrays, only the keys will be used.
     * @var array
     */
    public $languages;

    /**
     * @var string the default language.
     * Example: 'en'.
     */
    public $defaultLanguage;

    /**
     * @var string the name of translation model class.
     */
    public $langClassName;

    /**
     * @var string the name of the foreign key field of the translation table related to base model table.
     */
    public $langForeignKey;

    /**
     * @var string the name of the lang field of the translation table. Default to 'language'.
     */
    public $languageField = 'language';

    /**
     * Current language
     * @var string 
     */
    private $_currentLanguage;

    /**
     * @inheritdoc
     */
    public function events() {
        return [
            ActiveRecord::EVENT_INIT => 'afterInit',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
        ];
    }

    /**
     * @inheritdoc
     */
    public function attach($owner) {
        parent::attach($owner);
        $this->configure();
    }

    /**
     * controls if all needed attributes are set and initialises behaviour configuration
     */
    public function configure() {
        
        if (!$this->languages || !is_array($this->languages)) {
            throw new Exception(Yii::t('ml', 'Please specify array of available languages for the {behavior} in the {owner} or in the application parameters', ['behavior' => get_class($this), 'owner' => get_class($this->owner)]));
        } elseif (array_values($this->languages) !== $this->languages) { //associative array
            $this->languages = array_values($this->languages);
        }

        if (!$this->attributes) {
            throw new Exception(Yii::t('ml', 'Please specify multilingual attributes for the {behavior} in the {owner}', ['behavior' => get_class($this), 'owner' => get_class($this->owner)]));
        }

        if (!$this->langClassName) {
            throw new Exception(Yii::t('ml', 'Please specify multilingual langClassName for the {behavior} in the {owner}', ['behavior' => get_class($this), 'owner' => get_class($this->owner)]));
        }
        if (!isset($this->langForeignKey)) {
            throw new Exception(Yii::t('ml', 'Please specify langForeignKey for the {behavior} in the {owner}', ['behavior' => get_class($this), 'owner' => get_class($this->owner)]));
        }

        if (!isset($this->owner->primaryKey())) {
            throw new InvalidConfigException(Yii::t('ml', '{owner} must have a primary key.', [ 'owner' => get_class($this->owner)]));
        }

        if (!$this->defaultLanguage) {
            $language = isset(Yii::$app->params['defaultLanguage']) && Yii::$app->params['defaultLanguage'] ?
                    Yii::$app->params['defaultLanguage'] : Yii::$app->language;
            $this->defaultLanguage = $language;
        }

        if (!$this->_currentLanguage) {
            $this->_currentLanguage = Yii::$app->language;
        }
    }

    /**
     * Relation to model translations
     * @return ActiveQuery
     */
    public function getTranslations() {
        /* @var $owner ActiveRecord */
        $owner = $this->owner;
        return $owner->hasMany($this->langClassName, [$this->langForeignKey => $owner->primaryKey()])->indexBy($this->languageField);
    }

    /**
     * Relation to model translation
     * @param $language
     * @return ActiveQuery
     */
    public function getTranslation($language = null) {
        /* @var $owner ActiveRecord */
        $owner = $this->owner;
        $language = $language ? $language : $this->_currentLanguage;
        return $this->getTranslations()->where([$this->languageField => $language]);
    }

    /**
     * Handle 'afterFind' event of the owner.
     */
    public function afterFind() {
        /* @var $owner ActiveRecord */
        $owner = $this->owner;

        if ($owner->isRelationPopulated('translations')) {

            $related = $owner->getRelatedRecords();

            foreach ($this->languages as $lang) {
                foreach ($this->attributes as $attribute) {

                    $attributeValue = null;
                    if ($related['translations']) {
                        $translations = $this->indexByLanguage($related['translations']);
                        foreach ($translations as $translation) {
                            if ($translation->{$this->languageField} == $lang) {
                                $attributeName = $this->localizedPrefix . $attribute;
                                $attributeValue = isset($translation->$attributeName) ? $translation->$attributeName : null;
                                $this->setLangAttribute($attribute . '_' . $lang, $attributeValue);
                            }
                        }
                    }
                }
            }
        } elseif ($owner->isRelationPopulated('translation')) {
            $related = $owner->getRelatedRecords();

            if ($related['translation']) {
                $translation = $related['translation'][0];

                foreach ($this->attributes as $attribute) {
                    $attribute_name = $this->localizedPrefix . $attribute;
                    if ($translation->$attribute_name || $this->forceOverwrite) {
                        $owner->setAttribute($attribute, $translation->$attribute_name);
                        $owner->setOldAttribute($attribute, $translation->$attribute_name);
                    }
                }
            }
        }
    }

    /**
     * Handle 'afterInit' event of the owner.
     */
    public function afterInit() {
        if ($this->owner->isNewRecord) {
            $owner = new $this->langClassName;
            foreach ($this->languages as $lang) {
                foreach ($this->attributes as $attribute) {
                    $ownerFiled = $this->localizedPrefix . $attribute;
                    $this->setLangAttribute($attribute . '_' . $lang, $owner->{$ownerFiled});
                }
            }
        }
    }

    /**
     * Handle 'beforeValidate' event of the owner.
     */
    public function beforeValidate() {
        if ($this->owner->isNewRecord && $this->forceOverwrite) {
            foreach ($this->attributes as $attribute) {
                $lAttr = $attribute . "_" . $this->defaultLanguage;
                $this->owner->$lAttr = $this->owner->$attribute;
            }
        }
    }

    /**
     * Handle 'afterInsert' event of the owner.
     */
    public function afterInsert() {
        $this->saveTranslations();
    }

    /**
     * Handle 'afterUpdate' event of the owner.
     */
    public function afterUpdate() {
        /** @var ActiveRecord $owner */
        $owner = $this->owner;

        $translations = [];
        if ($owner->isRelationPopulated('translations'))
            $translations = $this->indexByLanguage($owner->getRelatedRecords()['translations']);

        $this->saveTranslations($translations);
    }

    private function saveTranslations($translations = []) {
        /** @var ActiveRecord $owner */
        $owner = $this->owner;

        foreach ($this->languages as $lang) {
            $defaultLanguage = $lang == $this->defaultLanguage;
            if (!isset($translations[$lang])) {
                $translation = new $this->langClassName;
                $translation->{$this->languageField} = $lang;
                $translation->{$this->langForeignKey} = $owner->getPrimaryKey();
            } else {
                $translation = $translations[$lang];
            }
            foreach ($this->attributes as $attribute) {
                if ($defaultLanguage)
                    $value = $owner->$attribute;
                else
                    $value = $this->getLangAttribute($attribute . "_" . $lang);

                if ($value !== null) {
                    $field = $this->localizedPrefix . $attribute;
                    $translation->$field = $value;
                }
            }
            $translation->save(false);
        }
    }

    /**
     * @inheritdoc
     */
    public function canGetProperty($name, $checkVars = true) {
        return method_exists($this, 'get' . $name) || $checkVars && property_exists($this, $name) || $this->hasLangAttribute($name);
    }

    /**
     * @inheritdoc
     */
    public function canSetProperty($name, $checkVars = true) {
        if (method_exists($this, 'set' . $name) || $checkVars && property_exists($this, $name)) {
            return true;
        } else {
            foreach ($this->languages as $lang) {
                foreach ($this->attributes as $attribute) {
                    if ($name == $attribute . '_' . $lang)
                        return true;
                }
            }
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function __get($name) {
        try {
            return parent::__get($name);
        } catch (Exception $e) {
            if ($this->hasLangAttribute($name))
                return $this->getLangAttribute($name);
            else
                throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value) {
        try {
            parent::__set($name, $value);
        } catch (Exception $e) {
            if ($this->hasLangAttribute($name))
                $this->setLangAttribute($name, $value);
            else
                throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function __isset($name) {
        if (!parent::__isset($name)) {
            return ($this->hasLangAttribute($name));
        } else {
            return true;
        }
    }

    /**
     * Whether an attribute exists
     * @param string $name the name of the attribute
     * @return boolean
     */
    public function hasLangAttribute($name) {
        return array_key_exists($name, $this->_langAttributes);
    }

    /**
     * @param string $name the name of the attribute
     * @return string the attribute value
     */
    public function getLangAttribute($name) {
        return $this->hasLangAttribute($name) ? $this->_langAttributes[$name] : null;
    }

    /**
     * @param string $name the name of the attribute
     * @param string $value the value of the attribute
     */
    public function setLangAttribute($name, $value) {
        $this->_langAttributes[$name] = $value;
    }

    /**
     * @param $records
     * @return array
     */
    private function indexByLanguage($records) {
        $sorted = array();
        foreach ($records as $record) {
            $sorted[$record->{$this->languageField}] = $record;
        }
        unset($records);
        return $sorted;
    }

}
