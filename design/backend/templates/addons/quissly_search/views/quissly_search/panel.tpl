{* Quissly Admin Panel: the body is built (and escaped) by Quissly\Search\Panel\PanelView. *}
{capture name="mainbox"}
    {$quissly_panel_html nofilter}
{/capture}
{include file="common/mainbox.tpl" title=__("quissly_admin_panel") content=$smarty.capture.mainbox}
