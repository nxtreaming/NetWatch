<?php

interface MailerInterface {
    public function sendProxyAlert($failedProxies);
}
