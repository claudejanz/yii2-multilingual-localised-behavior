Yii2 multilingual localised behavior
====================================


[![Latest Stable Version](https://poser.pugx.org/claudejanz/yii2-multilingual-localised-behavior/v/stable.svg)](https://packagist.org/packages/claudejanz/yii2-multilingual-localised-behavior) [![Total Downloads](https://poser.pugx.org/claudejanz/yii2-multilingual-localised-behavior/downloads.svg)](https://packagist.org/packages/claudejanz/yii2-multilingual-localised-behavior) [![Latest Unstable Version](https://poser.pugx.org/claudejanz/yii2-multilingual-localised-behavior/v/unstable.svg)](https://packagist.org/packages/claudejanz/yii2-multilingual-localised-behavior) [![License](https://poser.pugx.org/claudejanz/yii2-multilingual-localised-behavior/license.svg)](https://packagist.org/packages/claudejanz/yii2-multilingual-localised-behavior)

This behavior allows you to create multilingual models and almost use them as normal models. Translations are stored in a separate table in the database (ex: PostLang or ProductLang) for each model, so you can add or remove a language easily, without modifying your database.

Examples
--------

Example #1: current language translations are inserted to the model as normal attributes by default.

```php
//Assuming current language is english

$model = Post::findOne(1);
echo $model->title; //echo "English title"

//Now let's imagine current language is french 
$model = Post::findOne(1);
echo $model->title; //echo "Titre en Français"

$model = Post::find()->localized('en')->one();
echo $model->title; //echo "English title"

//Current language is still french here
```

Example #2: if you use `multilingual()` in a `find()` query, every model translation is loaded as virtual attributes (title_en, title_fr, title_de, ...).

```php
$model = Post::find()->multilingual()->one();
echo $model->title_en; //echo "English title"
echo $model->title_fr; //echo "Titre en Français"
```

Installation
------------

Preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist claudejanz/yii2-multilingual-localised-behavior "*"
```

or add

```
"claudejanz/yii2-multilingual-localised-behavior": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Here an example of base 'post' table :

```sql
CREATE TABLE IF NOT EXISTS `post` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(255) NOT NULL,
    `content` TEXT NOT NULL,
    `created_at` datetime NOT NULL,
    `updated_at` datetime NOT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

And its associated translation table (configured as default), assuming translated fields are 'title' and 'content':

```sql
CREATE TABLE IF NOT EXISTS `postLang` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `post_id` int(11) NOT NULL,
    `language` varchar(6) NOT NULL,
    `title` varchar(255) NOT NULL,
    `content` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `post_id` (`post_id`),
    KEY `language` (`language`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

ALTER TABLE `postLang`
ADD CONSTRAINT `postlang_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `post` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
```

Attaching this behavior to the model (Post in the example). Commented fields have default values.

```php
public function behaviors()
{
    return [
        'ml' => [
            'class' => MultilingualBehavior::className(),
            'languages' => [
                'fr' => 'French',
                'en-US' => 'English',
            ],
            'languageField' => 'language',
            
            'langClassName' => PostLang::className(),
            'defaultLanguage' => 'fr',
            'langForeignKey' => 'post_id',
            'tableName' => "{{%postLang}}",
            'attributes' => [
                'title', 'content',
            ]
        ],
    ];
}
```

Behavior attributes:
* languages Available languages. It can be a simple array: array('fr', 'en') or an associative array: array('fr' => 'Français', 'en' => 'English') (required)
* languageField The name of the language field of the translation table. Default is 'language'.
* langClassName The name of translation model class. (required)
* defaultLanguage The default language. (required)
* langForeignKey The name of the foreign key field of the translation table related to base model table. (required)
* attributes Multilingual attributes (required)

Then you have to overwrite the `find()` method in your model

```php
    public static function find()
    {
        $q = new MultilingualQuery(get_called_class());
        return $q;
    }
```

Add this function to the model class to retrieve translated models by default:
```php
    public static function find()
    {
        $q = new MultilingualQuery(get_called_class());
        $q->localized();
        return $q;
    }
```



Form example:
```php
//title will be saved to model table and as translation for default language
$form->field($model, 'title')->textInput(['maxlength' => 255]);
$form->field($model, 'title_en')->textInput(['maxlength' => 255]);
```
