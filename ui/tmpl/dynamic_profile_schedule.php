<?php
declare(strict_types=1);

/** CHIM schedule controls with the existing LORKHAN form names and narrator inheritance. */
function lorkhan_dynamic_profile_schedule(array $values,string $prefix,bool $narrator=false):void
{
    $fields=['interval_days'=>['Update every (game days)',1/24,365,'any',1],
        'min_events'=>['Minimum new events',1,10000,1,30],
        'cooldown_minutes'=>['Cooldown (real minutes)',1,1440,1,5]];
    echo '<div class="dynamic-profile-schedule" style="margin:12px 0"><p>'.($narrator?
        'The narrator updates when all three conditions are met.':
        'Each NPC updates when all three conditions are met, wherever they are in the game. Events count separately for each NPC.').'</p>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:12px">';
    foreach($fields as$key=>[$label,$min,$max,$step,$default]){
        $name=lorkhan_ui_h($prefix.$key);$value=lorkhan_ui_h((string)($values[$key]??($narrator?'':$default)));
        echo '<label style="display:flex;flex-direction:column;gap:6px" for="'.$name.'">'.$label.
            '<input style="width:100%;box-sizing:border-box" id="'.$name.'" name="'.$name.'" type="number" min="'.$min.'" max="'.$max.'" step="'.$step.'" value="'.$value.'"'.
            ($narrator?' placeholder="Inherit (default '.$default.')"':' required').'></label>';
    }
    echo '</div><p class="hint">The cooldown also applies after a failed attempt.</p></div>';
}
