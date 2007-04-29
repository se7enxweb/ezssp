<div class="block">

<label>{'Skeleton node'|i18n( 'extension/ezssp' )}:</label>
{if $event.skeleton_node_id}
{let $skeleton=fetch('content','node',hash('node_id',$event.skeleton_node_id))}
<a href={$skeleton.url_alias|ezurl}>{$skeleton.name|wash}</a>
{/let}
{else}
no node selected yet
{/if}
&nbsp;<input type="submit" class="button" name="CustomActionButton[{$event.id}_SelectSkeleton]" value="{'Select skeleton node'|i18n( 'extension/ezssp' )}" />
</div>

<div class="block">
<fieldset>
<legend>{'Subtree-limited role assignments'|i18n( 'extension/ezssp' )}</legend>

<table class="list">
<tr>
    <th class="tight"></th>
    <th>Skeleton user group</th>
    <th>Roles to assign</th>
    <th class="tight">Add owner</th>
</tr>
{foreach $event.skeleton_user_groups as $groupNodeID => $groupConfig sequence array('bglight','bgdark') as $sequence}
<tr class="{$sequence}">
    <td><input type="checkbox" name="DeleteUserGroupIDList_{$event.id}[]" value="{$groupNodeID}" /></td>
    <td>
    {let $group=fetch('content','node',hash('node_id', $groupNodeID))}<a href={$group.url_alias|ezurl}>{$group.name|wash}</a>{/let}</td>
    <td>
    <select name="UserGroupRoleList_{$event.id}[{$groupNodeID}][]" multiple="multiple">
{foreach $event.role_list as $role}
    <option value="{$role.id}" {if $groupConfig.roles|contains($role.id)}selected="selected"{/if}>{$role.name|wash}</option>
{/foreach}
    </select>
    </td>
    <td>
    <input type="checkbox" name="UserGroupAddOwner_{$event.id}[]" {if $groupConfig.add_owner}checked="checked"{/if} value="{$groupNodeID}" />
    </td>
</tr>
{/foreach}
</table>

{if $event.skeleton_user_groups}
<input type="submit" class="button" name="CustomActionButton[{$event.id}_RemoveSkeletonUserGroups]" value="{'Remove selected'|i18n( 'extension/ezssp' )}" />
{else}
<input type="submit" class="button-disabled" disabled="disabled" name="CustomActionButton[{$event.id}_RemoveSkeletonUserGroups]" value="{'Remove selected'|i18n( 'extension/ezssp' )}" />
{/if}

{if $event.skeleton_node_id}
<input type="submit" class="button" name="CustomActionButton[{$event.id}_AddSkeletonUserGroups]" value="{'Add skeleton user groups'|i18n( 'extension/ezssp' )}" />
{else}
<input type="submit" class="button-disabled" disabled="disabled" name="CustomActionButton[{$event.id}_AddSkeletonUserGroups]" value="{'Add skeleton user groups'|i18n( 'extension/ezssp' )}" />
{/if}

</fieldset>

</div>
