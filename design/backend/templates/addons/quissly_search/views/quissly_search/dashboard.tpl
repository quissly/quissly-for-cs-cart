{* Quissly Dashboard: the body is built (and escaped) by Quissly\Search\Admin\DashboardView. *}
{capture name="mainbox"}
    {$quissly_dashboard_html nofilter}
{/capture}
{include file="common/mainbox.tpl" title=__("quissly_dashboard") content=$smarty.capture.mainbox}
