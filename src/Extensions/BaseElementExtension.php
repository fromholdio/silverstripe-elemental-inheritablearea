<?php

namespace Fromholdio\ElementalInheritableArea\Extensions;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\SiteConfig\SiteConfig;

class BaseElementExtension extends Extension
{
    public function updateCMSEditLink(&$link)
    {
        $owner = $this->getOwner();

        $relationName = $owner->getAreaRelationName();
        $page = $owner->getPage(true);

        if (!$page) {
            return;
        }

        if ($page instanceof SiteConfig) {
            $link = Controller::join_links(
                $page->CMSEditLink(),
                'EditForm/field/' . $relationName . '/item/',
                $owner->ID
            );
        }
    }

    public function canDelete($member)
    {
        if ($this->getOwner()->hasMethod('getPage')) {
            if ($page = $this->getOwner()->getPage()) {
                if (!$page->hasMethod('canArchive')) {
                    return $page->canDelete($member);
                }
            }
        }
    }
}
