<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Compact game-menu projection. Paths are an allowlist, never arbitrary JSON edits or provider secrets. */
final class InGameSettings
{
    public static function fields(string $scope,array $content): array
    {
        $definitions=[];
        if($scope==='global'){
            foreach(SettingsCatalog::clientDefaults()['behavior']as$key=>$value){
                if(in_array('behavior.'.$key,SettingsCatalog::compatibilityPaths(),true))continue;
                $definitions['behavior.'.$key]=['path'=>['client','behavior',$key],'default'=>$value];
            }
            foreach(['profile_management','relationship']as$section)foreach(SettingsCatalog::globalDefaults()[$section]as$key=>$value)
                $definitions[$section.'.'.$key]=['path'=>[$section,$key],'default'=>$value];
            foreach(['sections','details']as$group)foreach(SettingsCatalog::globalDefaults()['context'][$group]as$key=>$value)
                $definitions['context.'.$group.'.'.$key]=['path'=>['context',$group,$key],'default'=>$value];
        }elseif($scope==='core_profile'){
            foreach(['behavior'=>['rechat'=>false,'rechat_max_depth'=>2,'rechat_probability_percent'=>50,'rechat_allow_actions'=>false],
                'memory'=>['recent_turn_limit'=>20,'short_term_enabled'=>true,'mid_term_enabled'=>true,'long_term_enabled'=>true],
                'diary'=>DiaryGenerationPolicy::defaults()]as$section=>$fields){
                foreach($fields as$key=>$value)if(is_bool($value)||is_int($value))
                    $definitions[$section.'.'.$key]=['path'=>['settings_overrides',$section,$key],'default'=>$value];
            }
        }elseif($scope==='npc'){
            foreach(['personality','speech_style','goals','notes','occupation','appearance']as$key)
                $definitions['character.'.$key]=['path'=>[$key],'default'=>''];
            foreach(['locked','favorite']as$key)$definitions['management.'.$key]=['path'=>['management',$key],'default'=>false];
        }else throw new \InvalidArgumentException('invalid_settings_scope');
        $result=[];
        foreach($definitions as$key=>$definition){
            $value=$content;foreach($definition['path']as$part)$value=is_array($value)&&array_key_exists($part,$value)?$value[$part]:null;
            $value??=$definition['default'];
            if(!is_scalar($value)||strlen((string)$value)>512)continue;
            $kind=is_bool($value)?'boolean':(is_int($value)?'integer':'string');
            $field=['key'=>$key,'label'=>ucwords(str_replace(['_','.'],[' ',' / '],$key)),
                'kind'=>$kind,'value'=>is_bool($value)?($value?'true':'false'):(string)$value];
            if($kind==='integer'){
                $range=SettingsCatalog::ranges()[$key]??match($key){
                    'profile_management.autofill_custom_profiles_trigger'=>[10,100],
                    'diary.automatic_interval_seconds'=>[30,86400],'diary.context_turn_limit'=>[1,100],default=>[0,100]};
                [$field['minimum'],$field['maximum']]=$range;
            }
            if(isset(SettingsCatalog::enums()[$key])){
                $field['kind']='choice';$field['choices']=array_map(static fn(string $choice):array=>['value'=>$choice,'label'=>ucfirst($choice)],SettingsCatalog::enums()[$key]);
            }
            $result[]=$field+['_path'=>$definition['path']];
        }
        return array_slice($result,0,64);
    }

    /** Change exactly one currently displayed field; validate the full typed document before persistence. */
    public static function apply(string $scope,array $content,string $key,string $value): array
    {
        $field=null;foreach(self::fields($scope,$content)as$candidate)if($candidate['key']===$key)$field=$candidate;
        if($field===null||strlen($value)>512||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0"))throw new \InvalidArgumentException('invalid_setting');
        $typed=$value;
        if($field['kind']==='boolean'){
            if(!in_array($value,['true','false'],true))throw new \InvalidArgumentException('invalid_setting');$typed=$value==='true';
        }elseif($field['kind']==='integer'){
            $typed=filter_var($value,FILTER_VALIDATE_INT);
            if($typed===false||$typed<$field['minimum']||$typed>$field['maximum'])throw new \InvalidArgumentException('invalid_setting');
        }elseif($field['kind']==='choice'&&!in_array($value,array_column($field['choices'],'value'),true))throw new \InvalidArgumentException('invalid_setting');
        $target=&$content;foreach($field['_path']as$part)$target=&$target[$part];$target=$typed;unset($target);
        return match($scope){'global'=>EffectiveSettingsResolver::validateGlobalSettings($content),
            'core_profile'=>EffectiveSettingsResolver::validateCoreProfile($content),default=>$content};
    }
}
