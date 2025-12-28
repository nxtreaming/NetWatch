<?php

require_once __DIR__ . '/MailerInterface.php';

class MailerFactory {
    public static function create(): MailerInterface {
        $rootDir = dirname(__DIR__);
        if (file_exists($rootDir . '/vendor/autoload.php')) {
            require_once $rootDir . '/mailer.php';
            return new Mailer();
        }

        require_once $rootDir . '/mailer_simple.php';
        return new SimpleMailer();
    }
}
