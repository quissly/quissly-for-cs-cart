{*
    quissly_search storefront markup: the config elements the scripts read, and the
    QChat widget element. Here and not in scripts.post.tpl, because CS-Cart keeps only
    the <script> tags of the scripts hook (it bundles them at the end of the body).
    What renders is decided in PHP (func.php fn_quissly_search_storefront ->
    lib/Storefront.php); the config JSON is escaped for the attribute.
*}
{$quissly = ""|fn_quissly_search_storefront}
{if $quissly.overlay}
    <div data-quissly-overlay data-quissly-config="{$quissly.overlay_json|escape:"html"}" hidden></div>
{/if}
{if $quissly.media}
    <div data-quissly-media data-quissly-config="{$quissly.media_json|escape:"html"}" hidden></div>
{/if}
{if $quissly.qchat_agent_id}
    <ai-chatbot agent-id="{$quissly.qchat_agent_id|escape:"html"}"></ai-chatbot>
    <div data-quissly-cart data-quissly-config="{$quissly.cart_json|escape:"html"}" hidden></div>
{/if}
{* Which engine answered this search - only on ?quissly_debug=1 (lib/SearchOrigin.php). Here,
   not in scripts.post.tpl: CS-Cart collects that hook into its script bundle and drops markup. *}
{""|fn_quissly_search_debug_badge nofilter}
