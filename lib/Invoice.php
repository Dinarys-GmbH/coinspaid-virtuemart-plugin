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

use Coinspaid\Model\Config;

class Invoice extends AbstractResource
{
    protected const RESOURCE_PATH = '/invoices/create';


    public function __construct(Config $config)
    {
        parent::__construct($config);
    }

    public function send(array $params): bool
    {
        return parent::post($params);
    }

    public function getUrl(): string
    {
        return $this->data['url'] ?? '';
    }
}
