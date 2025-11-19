<?php

use Spliff\Utils\SendMail;

require('../vendor/autoload.php');

const DOMAIN_NAME = 'yoursite.com';
$_REQUEST['name'] = 'Inquirer Name';
$_REQUEST['text'] = "
Lorem ipsum dolor sit amet, consectetur adipiscing
elit, sed do eiusmod tempor incididunt ut labore
et dolore magna aliqua. Ut enim ad minim veniam,
quis nostrud exercitation ullamco laboris nisi ut
aliquip ex ea commodo consequat. Duis aute irure
dolor in reprehenderit in voluptate velit esse
cillum dolore eu fugiat nulla pariatur. Excepteur
sint occaecat cupidatat non proident, sunt in
culpa qui officia deserunt mollit anim id est
laborum.


Аналог лорем испум не-анси текста, проверяем.

我们正在检查lorem ispum非ansi文本类似物。

";
$_REQUEST['email'] = 'inquirer.email@example.com';

$to      = 'your.mailbox@someserver.com';
$subject = '['. DOMAIN_NAME .'] Site form inquiry';
$message = $_REQUEST['name']. ' ['.$_REQUEST['email'].'] made an inquiry:'."\r\n==\r\n".$_REQUEST['text'];

$smtp = SendMail::newInstance();
$smtp->setSmtpDsn('ssl://username:password@smtp.someserver.com:465')
    ->setFrom('robot@yoursite.com')
    ->setReplyTo($_REQUEST['email'])
    ->addHeader('Message-ID', uniqid().'@'. DOMAIN_NAME)
    ->addHeader('X-Mailer', 'thefish/phpsendmail@'.phpversion())
    ->setSubject($subject)
    ->setBody($message)
    ->addRecipient($to)
    ->addAttachment('1x1.png')
    ->addAttachment('1x1.png')
;

try {
    $smtp->send();
} catch (Exception $e) {
    print_r($e);
    throw $e;
}
