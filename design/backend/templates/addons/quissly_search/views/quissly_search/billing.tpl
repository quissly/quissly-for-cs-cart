{* Quissly Billing: the body is built (and escaped) by Quissly\Search\Admin\BillingPage; the
   stylesheet and script are the copies shared with quissly-for-magento and -woocommerce. *}
{style src="addons/quissly_search/billing.css"}
{script src="js/addons/quissly_search/billing.js"}
{capture name="mainbox"}
    {$quissly_billing_html nofilter}
{/capture}
{include file="common/mainbox.tpl" title=__("quissly_search.bi_title") content=$smarty.capture.mainbox}
