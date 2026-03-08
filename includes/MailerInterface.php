<?php

interface MailerInterface {
    public function sendMail($subject, $body, $isHTML = true);
    public function sendProxyAlert($failedProxies);
    public function sendStatusReport($stats);
}
