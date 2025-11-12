<?php

namespace XD\EventTickets\Discounts\Extensions;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;

/**
 * Class DiscountEventExtension
 * @package XD\EventTickets\Discounts
 * @property \CalendarEvent $owner
 */
class DiscountEventExtension extends DataExtension
{
    private static $db = [
        'DisableDiscountField' => 'Boolean'
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab('Root.Tickets', [
            CheckboxField::create('DisableDiscountField', _t(__CLASS__ . '.DisableDiscountField', 'Disable discount field'))
        ]);
    }
}
