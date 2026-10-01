{*
    quissly_search storefront scripts (their markup is in footer.post.tpl). Each loads
    only where lib/Storefront.php says the feature is on the page.
*}
{if $addons.gdpr.status == "A"}
{* The GDPR add-on's cookie banner (Klaro) reads this add-on's service texts from here. *}
<script>
    (function (_, $) {
        _.tr({
            "quissly_search.klaro_cookies_title": '{__("quissly_search.klaro_cookies_title", ['skip_live_editor' => true])|escape:"javascript"}',
            "quissly_search.klaro_cookies_description": '{__("quissly_search.klaro_cookies_description", ['skip_live_editor' => true])|escape:"javascript"}',
        });
    })(Tygh, Tygh.$);
</script>
{/if}
{$quissly = ""|fn_quissly_search_storefront}
{if $quissly.overlay}
    {script src="js/addons/quissly_search/overlay.js"}
{/if}
{if $quissly.media}
    {script src="js/addons/quissly_search/media.js"}
{/if}
{if $quissly.qchat_agent_id}
    {script src="js/addons/quissly_search/cart.js"}
    <script src="{$quissly.qchat_script}" async></script>
{/if}
