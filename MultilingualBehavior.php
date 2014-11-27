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
    private $_langAttributes = [];
    private $_isConfigured = false;
    private $_hasLangRules = false;

    /**
     * Relation to model translations
     * @return ActiveQuery
     */
    public function getTranslations() {
        /* @var $model ActiveRecord */
        $model = $this->owner;
        return $model->hasMany($this->langClassName, [$this->langForeignKey => join(',', $model->primaryKey())])->indexBy($this->languageField);
    }

    /**
     * Relation to model translation
     * @param $language
     * @return ActiveQuery
     */
    public function getTranslation($language = null) {
        /* @var $model ActiveRecord */
        $model = $this->owner;
        $language = $language ? $language : $this->_currentLanguage;
        return $model->hasOne($this->langClassName, [$this->langForeignKey => join(',', $model->primaryKey())])->where([$this->languageField => $language]);
    }

    public function setLanguage($language) {
        if (in_array($language, $this->languages)) {
            
            foreach ($this->attributes as $attribute) {
                if (isset($model->$attribute)) {
                    $model->{$attribute . '_' . $this->_currentLanguage} = $model->$attribute;
                }
                if (isset($model->{$attribute . '_' . $language})) {
                    $model->$attribute = $model->{$attribute . '_' . $language};
                }
            }
            $this->_currentLanguage = $language;
            return true;
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function events() {
        return [
            ActiveRecord::EVENT_INIT => 'afterInit',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
        ];
    }

    /**
     * Handle 'afterInit' event of the owner.
     */
    public function afterInit() {
        var_dump('init ' . get_class($this));
        if (!$this->_isConfigured) {
            var_dump('configure ' . get_class($this));
            /* @var $model ActiveRecord */
            $model = $this->owner;
            if (!$this->languages || !is_array($this->languages)) {
                throw new Exception(Yii::t('ml', 'Please specify array of available languages for the {behavior} in the {owner} or in the application parameters', ['behavior' => get_class($this), 'owner' => get_class($model)]));
            } elseif (array_values($this->languages) !== $this->languages) { //associative array
                $this->languages = array_unique(array_values($this->languages));
            }

            if (!$this->attributes) {
                throw new Exception(Yii::t('ml', 'Please specify multilingual attributes for the {behavior} in the {owner}', ['behavior' => get_class($this), 'owner' => get_class($model)]));
            }

            if (!$this->langClassName) {
                throw new Exception(Yii::t('ml', 'Please specify multilingual langClassName for the {behavior} in the {owner}', ['behavior' => get_class($this), 'owner' => get_class($model)]));
            }
            if (!isset($this->langForeignKey)) {
                throw new Exception(Yii::t('ml', 'Please specify langForeignKey for the {behavior} in the {owner}', ['behavior' => get_class($this), 'owner' => get_class($model)]));
            }

            if (null !== $model->getPrimaryKey()) {
                throw new InvalidConfigException(Yii::t('ml', '{owner} must have a primary key.', [ 'owner' => get_class($model)]));
            }

            if (!$this->defaultLanguage) {
                $language = isset(Yii::$app->params['defaultLanguage']) && Yii::$app->params['defaultLanguage'] ?
                        Yii::$app->params['defaultLanguage'] : Yii::$app->language;
                $this->defaultLanguage = $language;
            }

            if (!$this->_currentLanguage) {
                $this->_currentLanguage = Yii::$app->language;
            }
            // create empty attributes for each attribute
            foreach ($this->languages as $lang) {
                foreach ($this->attributes as $attribute) {
                    $this->setLangAttribute($attribute . '_' . $lang, null);
                }
            }
            $this->_isConfigured = true;
        }
    }

    /**
     * Handle 'afterFind' event of the owner.
     */
    public function afterFind() {
        var_dump('find ' . get_class($this));
        /* @var $model ActiveRecord */
        $model = $this->owner;
//        var_dump($model->isRelationPopulated('translations'));
//        var_dump($model->isRelationPopulated('translation'));
        if ($model->isRelationPopulated('translations')) {
            $translations = $model->translations;
            $validators = $model->getValidators();
            //var_dump($model->getValidators());
            foreach ($translations as $key => $translation) {
                /* @var $translation ActiveRecord */
                foreach ($translation->attributes() as $attribute) {
                    if (in_array($attribute, $this->attributes)) {
                        $model->setLangAttribute($attribute . '_' . $key, $translation->$attribute);
                        //$model->setOldAttribute($attribute . '_' . $key, $translation->$attribute);
                    }
                }
            }
        }
        if ($model->isRelationPopulated('translation')) {
            $related = $model->getRelatedRecords();
            if ($related['translation']) {
                $translation = $related['translation'];
                foreach ($this->attributes as $attribute) {
                    if ($translation->$attribute) {
                        $model->setAttribute($attribute, $translation->$attribute);
                        $model->setOldAttribute($attribute, $translation->$attribute);
                    }
                }
            }
        }
    }

    /**
     * Handle 'beforeValidate' event of the owner.
     * here we add validation rules for language attributes
     */
    public function beforeValidate() {
        /* @var $model ActiveRecord */
        $model = $this->owner;
        if (!$this->_hasLangRules) {

            $rules = $model->rules();
            $validators = $model->getValidators();
//       
            foreach ($rules as $rule) {
                if (is_array($rule[0])) {
                    $rule_attributes = $rule[0];
                } else {
                    $rule_attributes = array_map('trim', explode(',', $rule[0]));
                }
                //if (!in_array($rule[1], $this->_excludedValidators)) {
                foreach ($rule_attributes as $attribute) {
                    if (in_array($attribute, $this->attributes)) {
                        foreach ($this->languages as $language) {


                            if ($rule[1] !== 'required') {

                                $validators[] = Validator::createValidator($rule[1], $model, $attribute . '_' . $language, array_slice($rule, 2));
                            } elseif ($rule[1] === 'required') {
                                //We add a safe rule in case the attribute has a 'required' validation rule assigned
                                //and forceOverWrite == false
                                $validators[] = Validator::createValidator('safe', $model, $attribute . '_' . $language, array_slice($rule, 2));
                            }
                        }
                        //}
                    }
                }
            }
            $this->_hasLangRules = true;
        }
        if ($this->_currentLanguage != $this->defaultLanguage) {
            foreach ($this->attributes as $attribute) {

                if (isset($model->$attribute)) {
                    $model->{$attribute . '_' . $this->_currentLanguage} = $model->$attribute;
                }
                if (isset($model->{$attribute . '_' . $this->defaultLanguage})) {
                    $model->$attribute = $model->{$attribute . '_' . $this->defaultLanguage};
                }
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
        /* @var $model ActiveRecord  */
        $model = $this->owner;

        $translations = [];
        if ($model->isRelationPopulated('translations')) {
            $translations = $model->getRelatedRecords()['translations'];
        }
        $this->saveTranslations($translations);
    }
    
    /**
     * Invoked form 'afterUpdate' and 'afterInsert'.
     */

    private function saveTranslations($translations = []) {
        /** @var ActiveRecord $model */
        $model = $this->owner;

        foreach ($this->languages as $lang) {
            $isCurrentLanguage = $lang == $this->_currentLanguage;

            if (!isset($translations[$lang])) {
                $translation = new $this->langClassName;
                $translation->{$this->languageField} = $lang;
                $translation->{$this->langForeignKey} = $model->getPrimaryKey();
            } else {
                $translation = $translations[$lang];
            }
            $hasToBeSaved = false;
            foreach ($this->attributes as $attribute) {
                $value = null;
                if (isset($model->{$attribute . "_" . $lang})) {
                    $value = $model->{$attribute . "_" . $lang};
                } elseif ($isCurrentLanguage) {
                    $value = $model->$attribute;
                }

                if ($value !== null) {
                    $field = $attribute;
                    $translation->$field = $value;
                    $this->setLangAttribute($attribute . "_" . $lang, $value);
                    $hasToBeSaved = true;
                }
            }
            if ($hasToBeSaved) {
                $translation->save(false);
                $translations[$lang] = $translation;
            }
        }
        $model->populateRelation('translations', $translations);
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
        if ($this->hasLangAttribute($name)) {
            return $this->getLangAttribute($name);
        } else {
            return parent::__get($name);
        }
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value) {
        if ($this->hasLangAttribute($name)) {
            $this->setLangAttribute($name, $value);
        } else {
            parent::__set($name, $value);
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

}
