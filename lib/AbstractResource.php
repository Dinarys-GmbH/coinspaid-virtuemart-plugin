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

namespace Coinspaid;

use Coinspaid\Helper\Signature;
use Coinspaid\Model\Config;
use Joomla\CMS\Http\HttpFactory;
use Throwable;

abstract class AbstractResource
{
    private const API_BASE_PATH = 'https://app.cryptoprocessing.com/api/v2';
    private const SANDBOX_API_BASE_PATH = 'https://app.sandbox.cryptoprocessing.com/api/v2';
    private const DEFAULT_HEADERS = [
        'Accept-Charset' => 'utf-8',
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];

    protected array $errors = [];
    protected array $data = [];

    public function __construct(private Config $config)
    {

    }

    public function post(array $params): bool
    {
        try {
            $headers = array_merge(self::DEFAULT_HEADERS, Signature::buildSignatureHeaders($this->config, $params));

            $http = HttpFactory::getHttp();
            $response = $http->post($this->buildPath(), json_encode($params), $headers);
        } catch (Throwable $e) {
            $this->errors['exception'] = $e;
        }

        if (isset($response) && !in_array($response->code, [200, 201, 202])) {
            $responseBody = json_decode($response->body, true);
            $this->errors['response'] = $responseBody['data'] ?? $responseBody;
        }

        if (!empty($this->errors)) {
            $this->errors['body'] = $params;
            $this->errors['headers'] = $headers;

            return false;
        }

        $this->data = json_decode($response->body, true)['data'];

        return true;
    }

    private function buildPath(): string
    {
        $basePath = $this->config->useSandbox()
            ? self::SANDBOX_API_BASE_PATH
            : self::API_BASE_PATH;

        return $basePath.static::RESOURCE_PATH;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
