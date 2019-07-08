<?php

namespace Fromholdio\ElementalInheritableArea\Model;

use DNADesign\Elemental\Forms\ElementalAreaField;
use DNADesign\Elemental\Models\ElementalArea;
use Fromholdio\ElementalMultiArea\Extensions\FieldsElementalAreaExtension;
use SGN\HasOneEdit\HasOneEdit;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\SiteConfig\SiteConfig;
use UncleCheese\DisplayLogic\Forms\Wrapper;

class InheritableElementalArea extends ElementalArea
{
    const MODE_PARENT = 'parent';
    const MODE_SITE = 'site';
    const MODE_SELF = 'self';
    const MODE_NONE = 'none';

    private static $table_name = 'InheritableElementalArea';

    private static $allow_include_elements = true;

    private static $db = [
        'InheritMode' => 'Varchar(20)',
        'DoIncludeSiteElements' => 'Boolean',
        'DoIncludeParentElements' => 'Boolean'
    ];

    private static $extensions = [
        FieldsElementalAreaExtension::class
    ];

    private static $inherit_mode_labels = [
        self::MODE_PARENT => 'Inherit from parent',
        self::MODE_SITE => 'Use site settings',
        self::MODE_SELF => 'Custom settings',
        self::MODE_NONE => 'None'
    ];

    public function getInheritedElementalArea($relationName = null, $mode = null)
    {
        if (!$mode) {
            $mode = $this->InheritMode;
        }
        if (!$mode) {
            $mode = $this->getDefaultInheritMode();
        }

        if (!$relationName) {
            $relationName = $this->getOwnerPageRelationName();
        }

        $area = null;
        if ($mode === self::MODE_PARENT) {
            $parent = $this->getInheritParent();
            if ($parent) {
                $area = $parent->getInheritedElementalArea($relationName);
            }
        }
        else if ($mode === self::MODE_SITE) {
            $site = $this->getInheritSite();
            if ($site) {
                if ($site->hasMethod('getInheritedElementalArea')) {
                    $area = $site->getInheritedElementalArea($relationName);
                }
                else {
                    $area = $site->$relationName();
                }
            }
        }
        else if ($mode === self::MODE_SELF) {
            $area = $this;
        }

        $this->extend('updateInheritedElementalArea', $area, $mode);
        return $area;
    }

    public function getElementalCMSFields($relationName, $types)
    {
        $fields = FieldList::create();
        $defaultMode = $this->getDefaultInheritMode();
        $hasOneKey = $relationName . HasOneEdit::FIELD_SEPARATOR;

        $modeOptions = $this->getInheritModeOptions();
        if (!is_array($modeOptions) || !isset($modeOptions[$defaultMode])) {
            $modeOptions[$defaultMode] = 'Default';
        }

        if (!$this->InheritMode) {
            $this->InheritMode = $defaultMode;
        }

        if (count($modeOptions) > 1) {

            $page = $this->getOwnerPage();
            $label = $page->fieldLabel($relationName);
            if (!$label) {
                $label = $this->fieldLabel('InheritMode');
            }

            $modeField = OptionsetField::create(
                'InheritMode',
                $label,
                $modeOptions
            );
            $fields->push($modeField);
        }

        $fields->push(LiteralField::create($relationName . 'ElementalAreaFields', ''));

        if (isset($modeOptions[self::MODE_SELF])) {

            if ($this->config()->get('allow_include_elements')) {
                if (isset($modeOptions[self::MODE_SITE]) || isset($modeOptions[self::MODE_PARENT])) {

                    $elementsIncludeFieldGroup = FieldGroup::create('Blocks');

                    if (isset($modeOptions[self::MODE_SITE])) {
                        $includeSiteElementsField = CheckboxField::create(
                            'DoIncludeSiteElements',
                            'Include site blocks'
                        );
                        $elementsIncludeFieldGroup->push($includeSiteElementsField);
                    }

                    if (isset($modeOptions[self::MODE_PARENT])) {
                        $includeParentElementsField = CheckboxField::create(
                            'DoIncludeParentElements',
                            'Include parent blocks'
                        );
                        $elementsIncludeFieldGroup->push($includeParentElementsField);
                    }

                    $elementsIncludeWrapper = Wrapper::create($elementsIncludeFieldGroup);
                    $elementsIncludeWrapper->setName($relationName . 'IncludeElementsGroup');
                    $fields->push($elementsIncludeWrapper);

                    if (count($modeOptions) > 1) {
                        $elementsIncludeWrapper
                            ->displayIf($hasOneKey . 'InheritMode')
                            ->isEqualTo(self::MODE_SELF);
                    }
                }
            }

            $editorWrapper = Wrapper::create(
                ElementalAreaField::create(
                    $relationName,
                    $this->getOwner(),
                    $types
                )
            );
            $editorWrapper->setName($relationName . 'Group');
            $fields->push($editorWrapper);

            if (count($modeOptions) > 1) {
                $editorWrapper
                    ->displayIf($hasOneKey . 'InheritMode')
                    ->isEqualTo(self::MODE_SELF);
            }
        }

        $this->extend('updateElementalCMSFields', $fields);

        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if ($this->InheritMode === $this->getDefaultInheritMode()) {
            $this->InheritMode = null;
        }
    }

    public function getInheritParent()
    {
        $parent = null;
        $page = $this->getOwnerPage();
        if (is_a($page, SiteTree::class)) {
            if ($this->getIsMultisitesEnabled()) {
                if ($page->ParentID !== $page->SiteID) {
                    $parent = $page->Parent();
                }
            }
            else {
                $parent = $page->Parent();
            }
            if ($parent && !$parent->exists()) {
                $parent = null;
            }
        }
        $this->extend('updateInheritParent', $parent);
        return $parent;
    }

    public function getInheritSite()
    {
        $site = SiteConfig::current_site_config();
        $page = $this->getOwnerPage();
        if (is_a($page, SiteTree::class)) {
            if ($this->getIsMultisitesEnabled()) {
                $sitePage = $page->Site();
                if ($sitePage && $sitePage->exists()) {
                    $site = $sitePage;
                }
            }
        }
        $this->extend('updateInheritSite', $site);
        return $site;
    }

    public function getInheritModeOptions()
    {
        $options = $this->config()->get('inherit_mode_labels');

        $page = $this->getOwnerPage();
        if ($page) {
            $pageOptions = $page->config()->get('elemental_inherit_mode_labels');
            if ($pageOptions && is_array($pageOptions)) {
                foreach ($pageOptions as $key => $label) {
                    $options[$key] = $label;
                }
            }
        }

        foreach ($options as $key => $option) {
            if ($option === false) {
                unset($options[$key]);
            }
        }

        if (is_a($page, SiteTree::class)) {

            $parent = $this->getInheritParent();
            $site = $this->getInheritSite();

            if (!$parent) {
                if (isset($options[self::MODE_PARENT])) {
                    unset($options[self::MODE_PARENT]);
                }
            }

            if ($site && $this->getIsMultisitesEnabled()) {
                if ($page->ID === $site->ID) {
                    if (isset($options[self::MODE_SITE])) {
                        unset($options[self::MODE_SITE]);
                    }
                }
            }
            else if (!$site) {
                if (isset($options[self::MODE_SITE])) {
                    unset($options[self::MODE_SITE]);
                }
            }
        }

        $this->extend('updateInheritModeOptions', $options);
        return $options;
    }

    public function getDefaultInheritMode()
    {
        $mode = self::MODE_SELF;
        $options = $this->getInheritModeOptions();

        if (isset($options[self::MODE_PARENT])) {
            $mode = self::MODE_PARENT;
        }
        else if (isset($options[self::MODE_SITE])) {
            $mode = self::MODE_SITE;
        }

        $this->extend('updateDefaultInheritMode', $mode);
        return $mode;
    }

    public function getOwnerPageRelationName()
    {
        $name = null;
        $page = $this->getOwnerPage();
        if ($page && $page->hasMethod('getElementalRelations')) {
            $relationNames = $page->getElementalRelations();
            foreach ($relationNames as $relationName) {
                $fieldName = $relationName . 'ID';
                $areaID = $page->$fieldName;
                if ($areaID === $this->ID) {
                    $name = $relationName;
                    break;
                }
            }
        }
        $this->extend('updateOwnerPageRelationName', $name);
        return $name;
    }

    public function getIsMultisitesEnabled()
    {
        return ModuleLoader::inst()
            ->getManifest()
            ->moduleExists('symbiote/silverstripe-multisites');
    }
}