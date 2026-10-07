{* Quissly Setup: the body is built (and escaped) by Quissly\Search\Admin\SetupPage; the
   stylesheet and script are the copies shared with quissly-for-magento and -woocommerce. *}
{style src="addons/quissly_search/setup.css"}
{script src="js/addons/quissly_search/setup.js"}
{capture name="mainbox"}
    {$quissly_setup_html nofilter}
{/capture}
{include file="common/mainbox.tpl" title=__("quissly_search.su_title") content=$smarty.capture.mainbox}
