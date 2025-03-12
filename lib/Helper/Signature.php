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

namespace Coinspaid\Helper;

use Coinspaid\Model\Config;

class Signature
{
    private const PUBLIC_KEY_HEADER = 'X-Processing-Key';
    private const SIGNATURE_HEADER = 'X-Processing-Signature';

    public static function buildSignatureHeaders(Config $config, array $params): array
    {
        return [
            self::PUBLIC_KEY_HEADER => $config->getPublicKey(),
            self::SIGNATURE_HEADER => self::generateSignature($config->getSecretKey(), $params),
        ];
    }

    private static function generateSignature(string $secretKey, array $params): string
    {
        return hash_hmac('sha512', json_encode($params), $secretKey);
    }

    public static function validateSignatureHeaders(Config $config, array $params, array $headers): bool
    {
        if (!isset($headers[self::SIGNATURE_HEADER])) {
            return false;
        }

        return $headers[self::SIGNATURE_HEADER] === self::generateSignature($config->getSecretKey(), $params);
    }
}
