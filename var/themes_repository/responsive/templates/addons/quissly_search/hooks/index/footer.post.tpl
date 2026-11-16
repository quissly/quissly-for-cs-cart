{*
    quissly_search storefront markup: the config elements the scripts read, and the
    QChat widget element. Here and not in scripts.post.tpl, because CS-Cart keeps only
    the <script> tags of the scripts hook (it bundles them at the end of the body).
    What renders is decided in PHP (func.php fn_quissly_search_storefront ->
    lib/Storefront.php). Each attribute value is escaped ONCE in PHP (Storefront::attributes,
    the *_attr keys) and printed with nofilter: Smarty cannot do it the same everywhere - with
    CS-Cart's escape_html on, 4.11's Smarty 3.1 escapes an explicit html escape a second time and
    4.21's Smarty 5 drops it (and with nofilter escapes nothing).
*}
{$quissly = ""|fn_quissly_search_storefront}
{if $quissly.overlay}
    <div data-quissly-overlay data-quissly-config="{$quissly.overlay_json_attr nofilter}" hidden></div>
{/if}
{if $quissly.media}
    <div data-quissly-media data-quissly-config="{$quissly.media_json_attr nofilter}" hidden></div>
{/if}
{if $quissly.quick}
    <div data-quissly-quick data-quissly-config="{$quissly.quick_json_attr nofilter}" hidden></div>
{/if}
{if $quissly.events}
    <div data-quissly-events data-quissly-config="{$quissly.events_json_attr nofilter}" hidden></div>
{/if}
{if $quissly.qchat_agent_id}
    <ai-chatbot agent-id="{$quissly.qchat_agent_id_attr nofilter}"></ai-chatbot>
    <div data-quissly-cart data-quissly-config="{$quissly.cart_json_attr nofilter}" hidden></div>
{/if}
{* Which engine answered this search - only on ?quissly_debug=1 (lib/SearchOrigin.php). Here,
   not in scripts.post.tpl: CS-Cart collects that hook into its script bundle and drops markup. *}
{""|fn_quissly_search_debug_badge nofilter}
