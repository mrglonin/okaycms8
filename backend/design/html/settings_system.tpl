{$meta_title = $btr->settings_system_title scope=global}

{*Название страницы*}
<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="heading_page">{$btr->settings_system_title|escape}</div>
    </div>
</div>

{*Вывод успешных сообщений*}
{if $message_success && empty($message_error)}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--success">
                <div class="alert__content">
                    <div class="alert__title">
                        {if $message_success == 'saved'}
                            {$btr->general_settings_saved|escape}
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
{/if}

{*Вывод ошибок*}
{if $message_error}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--error">
                <div class="alert__content">
                    <div class="alert__title">
                        {if $message_error == 'system_config_save_error'}
                            {$btr->system_config_save_error|escape}
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
{/if}

{if $config_toggles}
<form method="post">
    <input type="hidden" name="session_id" value="{$smarty.session.id}">

    <div class="row">
        <div class="col-lg-12 col-md-12">
            <div class="boxed">
                <div class="heading_box">
                    {$btr->system_config_title|escape}
                </div>
                <div class="system_config_box">
                    <div class="system_config_intro">
                        {$btr->system_config_description|escape}
                        <code>config/config.php</code>
                    </div>
                    <div class="system_config_grid">
                        {foreach $config_toggles as $toggle}
                            <div class="system_config_option">
                                <div class="system_config_option__content">
                                    <div class="system_config_option__title">{$btr->getTranslation($toggle.label_key)|escape}</div>
                                    <div class="system_config_option__hint">{$btr->getTranslation($toggle.hint_key)|escape}</div>
                                </div>
                                <div class="system_config_option__switch">
                                    <div class="okay_switch clearfix">
                                        <label class="switch switch-default">
                                            <input class="switch-input" name="{$toggle.name|escape}" value="1" type="checkbox" {if $toggle.value}checked=""{/if}/>
                                            <span class="switch-label"></span>
                                            <span class="switch-handle"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        {/foreach}
                    </div>
                    <div class="system_config_footer">
                        <div class="system_config_footer__note">{$btr->system_config_master_note|escape}</div>
                        <button type="submit" class="btn btn_small btn_blue" name="save_system_config" value="1">
                            {$btr->general_apply|escape}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
{/if}

<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="boxed fn_toggle_wrap">
            <div class="heading_box">
                {$btr->settings_general_options|escape}
                <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                    <a class="btn-minimize" href="javascript:;" ><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                </div>
            </div>
            {*Параметры элемента*}
            <div class="toggle_body_wrap on fn_card">
               <div class="row">
                   {if $php_version}
                       <div class="col-lg-4 col-md-4 col-sm-12">
                           <div class="banner_card">
                               <div class="system_header">
                                   <span class="font-weight-bold">PHP Version</span>
                               </div>
                               <div class="banner_card_block">
                                   <div class="system_information">
                                       Version: {$php_version|escape}
                                   </div>
                               </div>
                           </div>
                       </div>
                   {/if}
                   {if $server_ip}
                   <div class="col-lg-4 col-md-4 col-sm-12">
                       <div class="banner_card">
                           <div class="system_header">
                               <span class="font-weight-bold">{$btr->system_server_ip}</span>
                           </div>
                           <div class="banner_card_block">
                               <div class="system_information">
                                   IP: {$server_ip|escape}
                               </div>
                           </div>
                           </div>
                       </div>
                   {/if}
                   {if $sql_info}
                       <div class="col-lg-4 col-md-6 col-sm-12">
                           <div class="banner_card">
                               <div class="system_header">
                                   <span class="font-weight-bold">SQL</span>
                               </div>
                               <div class="banner_card_block">
                                   <div class="system_information">
                                       {foreach $sql_info as $sql_param => $sql_ver}
                                           <div>
                                               <span>{$sql_param|escape}: </span>
                                               <span>{$sql_ver|escape}</span>
                                           </div>
                                       {/foreach}
                                   </div>
                               </div>
                           </div>
                       </div>
                   {/if}

                   {if $paths_info}
                   <div class="col-lg-12 col-md-12 col-sm-12">
                       <div class="banner_card">
                           <div class="system_header">
                               <span class="font-weight-bold">{$btr->system_paths_title|escape}</span>
                           </div>
                           <div class="banner_card_block">
                               <div class="system_information">
                                   <div class="system_matrix_wrap">
                                       <div class="system_matrix system_matrix--paths">
                                           <div class="system_matrix__head">
                                               <div class="system_matrix__cell">{$btr->general_name|escape}</div>
                                               <div class="system_matrix__cell">{$btr->system_path|escape}</div>
                                               <div class="system_matrix__cell">{$btr->system_writable|escape}</div>
                                           </div>
                                           {foreach $paths_info as $path_info}
                                               <div class="system_matrix__row">
                                                   <div class="system_matrix__cell system_matrix__label">{$path_info.label|escape}</div>
                                                   <div class="system_matrix__cell system_matrix__code"><code>{$path_info.path|escape}</code></div>
                                                   <div class="system_matrix__cell system_matrix__status {if $path_info.writable}system_matrix__status--positive{else}system_matrix__status--negative{/if}">
                                                       {if $path_info.writable}{$btr->index_yes|escape}{else}{$btr->index_no|escape}{/if}
                                                   </div>
                                               </div>
                                           {/foreach}
                                       </div>
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
                   {/if}

                   {if $composer_sections}
                       {foreach $composer_sections as $composer_section}
                       <div class="col-lg-12 col-md-12 col-sm-12">
                           <div class="banner_card">
                               <div class="system_header">
                                   <span class="font-weight-bold">{$btr->getTranslation($composer_section.title_key)|escape}</span>
                               </div>
                               <div class="banner_card_block">
                                   <div class="system_information">
                                       <div class="system_matrix_wrap">
                                           <div class="system_matrix system_matrix--packages">
                                               <div class="system_matrix__head">
                                                   <div class="system_matrix__cell">{$btr->general_name|escape}</div>
                                                   <div class="system_matrix__cell">{$btr->system_constraint|escape}</div>
                                                   <div class="system_matrix__cell">{$btr->system_installed_version|escape}</div>
                                                   <div class="system_matrix__cell">{$btr->system_reference|escape}</div>
                                               </div>
                                               {foreach $composer_section.packages as $package}
                                                   <div class="system_matrix__row">
                                                       <div class="system_matrix__cell system_matrix__code"><code>{$package.name|escape}</code></div>
                                                       <div class="system_matrix__cell">{$package.constraint|escape}</div>
                                                       <div class="system_matrix__cell">{$package.installed|escape}</div>
                                                       <div class="system_matrix__cell">{$package.reference|escape}</div>
                                                   </div>
                                               {/foreach}
                                           </div>
                                       </div>
                                   </div>
                               </div>
                           </div>
                       </div>
                       {/foreach}
                   {/if}

                   {if $all_extensions}
                   <div class="col-lg-12 col-md-12 col-sm-12">
                       <div class="banner_card">
                           <div class="system_header">
                               <span class="font-weight-bold">Server extensions</span>
                           </div>
                           <div class="banner_card_block">
                               <div class="system_information clearfix">
                                   {foreach $all_extensions as $ext_val}
                                   <div class="col-xl-3 col-lg-4 col-md-6">
                                       <div>
                                           <span>{$ext_val|escape}</span>
                                       </div>
                                   </div>
                                   {/foreach}
                               </div>
                           </div>
                       </div>
                   </div>
                   {/if}

                   {if $ini_params}
                       <div class="col-lg-4 col-md-4 col-sm-12">
                           <div class="banner_card">
                               <div class="system_header">
                                   <span class="font-weight-bold">INI params</span>
                               </div>
                               <div class="banner_card_block">
                                   <div class="system_information">
                                       {foreach $ini_params as $param_name => $param_value}
                                           <div>
                                               <span>{$param_name|escape}: </span>
                                               <span>{$param_value|escape}</span>
                                           </div>
                                       {/foreach}
                                   </div>
                               </div>
                           </div>
                       </div>
                   {/if}
                   {if $runtime_info}
                   <div class="col-lg-4 col-md-6 col-sm-12">
                       <div class="banner_card">
                           <div class="system_header">
                               <span class="font-weight-bold">{$btr->system_runtime_title|escape}</span>
                           </div>
                           <div class="banner_card_block">
                               <div class="system_information">
                                   {foreach $runtime_info as $runtime_item}
                                       <div class="d-flex justify-content-between">
                                           <span>{$btr->getTranslation($runtime_item.label_key)|escape}</span>
                                           <span>
                                               {if !empty($runtime_item.is_bool)}
                                                   {if $runtime_item.value}{$btr->index_yes|escape}{else}{$btr->index_no|escape}{/if}
                                               {else}
                                                   {$runtime_item.value|escape}
                                               {/if}
                                           </span>
                                       </div>
                                   {/foreach}
                               </div>
                           </div>
                       </div>
                   </div>
                   {/if}


                   <div class="col-lg-12 col-md-12 col-sm-12">
                       <div class="alert alert--icon alert--info">
                           <div class="alert__content">
                               <div class="alert__title mb-h">
                                   {$btr->alert_info|escape}
                               </div>
                               <div class="text_box">
                                   <div class="mb-1">
                                       {$btr->system_message_1|escape}
                                   </div>
                                   <div class="mb-h"><b>{$btr->system_message_2|escape}</b> </div>
                                   <div>
                                       <ul class="mb-0 pl-1">
                                           <li>display_errors - {$btr->system_display_errors|escape}</li>
                                           <li>memory_limit - {$btr->system_memory_limit|escape}</li>
                                           <li>post_max_size - {$btr->system_post_max_size|escape}</li>
                                           <li>max_input_time - {$btr->system_max_input_time|escape}</li>
                                           <li>max_file_uploads - {$btr->system_max_file_uploads|escape}</li>
                                           <li>max_execution_time - {$btr->system_max_execution_time|escape}</li>
                                           <li>upload_max_filesize - {$btr->system_upload_max_filesize|escape}</li>
                                           <li>max_input_vars - {$btr->system_max_input_vars|escape}</li>
                                       </ul>
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>
            </div>
        </div>
    </div>
</div>
