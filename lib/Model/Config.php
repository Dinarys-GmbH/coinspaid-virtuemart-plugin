<?php
/**
 *
 * @author Coinspaid
 * @version 1.0
 * @package VirtueMart
 * @subpackage Coinspaid
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 *
 * https://www.coinspaid.com
 */
\defined('_JEXEC') or die;

namespace Coinspaid\Model;

class Config
{
    private bool $sandbox;
    private bool $timeRestriction;
    private bool $enableDebug;
    private string $sandboxSecretKey;
    private string $sandboxPublicKey;
    private string $secretKey;
    private string $publicKey;

    public function __construct($paymentMethod)
    {
        $this->sandbox = (bool)$paymentMethod->sandbox;
        $this->timeRestriction = (bool)$paymentMethod->time_restriction;
        $this->enableDebug = (bool)$paymentMethod->enable_debug;
        $this->sandboxSecretKey = $paymentMethod->sandbox_secret_key;
        $this->sandboxPublicKey = $paymentMethod->sandbox_public_key;
        $this->secretKey = $paymentMethod->secret_key;
        $this->publicKey = $paymentMethod->public_key;
    }

    public function useSandbox(): bool
    {
        return $this->sandbox;
    }

    public function enableDebug(): bool
    {
        return $this->enableDebug;
    }

    public function timeRestriction(): bool
    {
        return $this->timeRestriction;
    }

    public function valid(): bool
    {
        return !(empty($this->getSecretKey()) || empty($this->getPublicKey()));
    }

    public function getSecretKey(): string
    {
        return $this->sandbox
            ? $this->sandboxSecretKey
            : $this->secretKey;
    }

    public function getPublicKey(): string
    {
        return $this->sandbox
            ? $this->sandboxPublicKey
            : $this->publicKey;
    }
}
