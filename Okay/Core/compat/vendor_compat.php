<?php

// Bridge legacy class names used by OkayCMS to namespaced vendor classes.

$aliases = [
    'Smarty'                 => \Smarty\Smarty::class,
    'Smarty_Internal_Data'   => \Smarty\Data::class,
    'Smarty_Internal_Template' => \Smarty\Template::class,
    'Smarty_Variable'        => \Smarty\Variable::class,
    'Smarty_Security'        => \Smarty\Security::class,
    'Smarty_Template_Cached' => \Smarty\Template\Cached::class,
    'Smarty_Template_Compiled' => \Smarty\Template\Compiled::class,
    'SmartyException'        => \Smarty\Exception::class,
    'Smarty_CompilerException' => \Smarty\CompilerException::class,
    'Smarty_Undefined_Variable' => \Smarty\UndefinedVariable::class,
    'Mobile_Detect'          => \Detection\MobileDetect::class,
];

foreach ($aliases as $legacyClass => $modernClass) {
    if (!class_exists($legacyClass, false) && class_exists($modernClass)) {
        class_alias($modernClass, $legacyClass);
    }
}
