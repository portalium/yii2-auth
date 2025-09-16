# {{Component}}

Documentation:

## Usage Example

Component:

Init Component File:

```php
<?php

namespace portalium\auth\components;

use Yii;
use yii\base\Component;

class {{Component}} extends Component
{
    
    public function generate()
    {
        return 'Hello World';
    }

}
```


Init component in config file:

```php
public function registerComponents()
    {
        return [
            'setting' => [
                'class' => 'portalium\auth\components\{{Component}}',
            ]
        ];
    }
```

Usage:

```php
echo Yii::$app->{{component}}->generate();
```

Result will be:

```php
Hello World
```