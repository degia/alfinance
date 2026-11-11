<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('mail.mailers.smtp');
echo "Mailer : ".config('mail.default').PHP_EOL;
echo "Host   : {$cfg['host']}:{$cfg['port']} ({$cfg['scheme']})".PHP_EOL;
echo "User   : {$cfg['username']}".PHP_EOL;
echo "From   : ".config('mail.from.address').PHP_EOL.PHP_EOL;

try {
    $sent = Illuminate\Support\Facades\Mail::raw('Tes dari Alfinance', function ($m) {
        $m->to('degia56.dp@gmail.com')->subject('Tes SMTP Zimbra '.now());
    });
    echo "Message-ID: ".$sent?->getMessageId().PHP_EOL;
    echo "---- SMTP log ----".PHP_EOL.$sent?->getDebug();
} catch (Throwable $e) {
    echo "ERROR: ".get_class($e).PHP_EOL.$e->getMessage().PHP_EOL;
}
