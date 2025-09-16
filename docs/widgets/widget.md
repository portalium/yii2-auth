# {{Widget}}

Represents `<{{widget}}>` element of type "{{type}}". It is rendered as button without default behavior. Documentation:

## Usage Example

Widget:

```php
echo Widget::widget()->content('Click Me');
```

Result will be:

```html
<div>
    <button type="button">Click Me</button>
</div>
```