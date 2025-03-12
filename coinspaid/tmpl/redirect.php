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
defined('_JEXEC') or die('Restricted access');
?>
<form action="<?php echo $viewData['url'] ?>" method="get" name="vm_coinspaid_form">
    <?php foreach ($viewData['elements'] as $key => $value) { ?>
        <input type="hidden" name="<?php echo $key ?>" value="<?php echo $value ?>"/>
    <?php } ?>
</form>
<script>
    document.getElementsByTagName("body")[0].style.display = "none";
    document.title = "Redirecting to Coinspaid...";
    document.vm_coinspaid_form.submit();
</script>
