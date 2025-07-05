```php
use LayBot\Chat;

$chat = new Chat('sk-xxx');

$chat->completions([
    'model' => 'LB-Cosmos',
    'stream'=> true,
    'messages'=>[['role'=>'user','content'=>'hi']]
],[
    'stream'=>function($chunk,$done){
        echo $done ? PHP_EOL : ($chunk['choices'][0]['delta']['content']??'');
    },
    'complete'=>function($json){ print_r($json); }
]);