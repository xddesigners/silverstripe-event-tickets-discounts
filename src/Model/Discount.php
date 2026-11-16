<?php
/**
 * Discount.php
 *
 * @author Bram de Leeuw
 * Date: 30/03/17
 */

namespace XD\EventTickets\Discounts\Model;

use XD\EventTickets\Model\Buyable;
use XD\EventTickets\Model\PriceModifier;
use XD\EventTickets\Model\Reservation;
use XD\EventTickets\Model\Ticket;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Security\Group;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Member;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Forms\NumericField;
use SilverStripe\TagField\TagField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;

/**
 * Class Discount
 *
 * @property string Code
 * @property string ValidFrom
 * @property string ValidTill
 * @property float  Amount
 * @property int    Uses
 * @property bool   AppliesTo
 * @property string DiscountType
 * @method ManyManyList Groups()
 * @method ManyManyList TicketPages()
 * @method ManyManyList Reservations()
 */
class Discount extends PriceModifier
{
    const PRICE = 'PRICE';
    const PERCENTAGE = 'PERCENTAGE';
    const APPLIES_EACH_TICKET = 'EACH_TICKET';

    private static $table_name = 'EventTickets_Discount';

    private static $db = [
        'Code' => 'Varchar(255)',
        'DiscountType' => 'Enum("PRICE,PERCENTAGE","PRICE")',
        'Amount' => 'Decimal',
        'AppliesTo' => 'Enum("CART,EACH_TICKET","CART")',
        'Uses' => 'Int',
        'ValidFrom' => 'Datetime',
        'ValidTill' => 'Datetime',
        'Description' => 'Text',
        'TicketType' => 'Varchar',
        'OncePerEmail' => 'Boolean'
    ];

    private static $default_sort = 'ValidFrom DESC';

    private static $many_many = [
        'Groups' => Group::class,
        'TicketPages' => SiteTree::class
    ];

    private static $indexes = [
        'Code' => 'unique("Code")'
    ];

    private static $summary_fields = [
        'Code',
        'Description',
        'ValidFrom',
        'ValidTill',
        'Reservations.Count' => 'Uses'
    ];

    private static $defaults = [
        'Uses' => 1
    ];

    public function populateDefaults()
    {
        parent::populateDefaults();
        $code = $this->generateCode();
        $this->Code = $code;
        $this->Title = $code;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Groups', 'TicketPages']);
        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Code', $this->fieldLabel('Code'))
                ->setDescription(_t(__CLASS__ . '.CodeHelp', 'The code can be customised')),
            TextareaField::create('Description', $this->fieldLabel('Description'))
                ->setDescription(_t(__CLASS__ . '.DescriptionHelp', 'The description is only visible in the cms')),
            DropdownField::create('DiscountType', $this->fieldLabel('DiscountType'))
                ->setSource($this->friendlyEnum('DiscountType')),
            DropdownField::create('AppliesTo', $this->fieldLabel('AppliesTo'))
                ->setSource($this->friendlyEnum('AppliesTo')),
            NumericField::create('Amount', $this->fieldLabel('Amount'))
                ->setScale(2),
        ]);

        $fields->addFieldsToTab('Root.Constraints', [
            NumericField::create('Uses', $this->fieldLabel('Uses'))
                ->setDescription(_t(__CLASS__ . '.UsesHelp', 'Set to "-1" for unlimited uses')),
            CheckboxField::create('OncePerEmail', $this->fieldLabel('OncePerEmail')),
            DateField::create('ValidFrom', $this->fieldLabel('ValidFrom')),
            DateField::create('ValidTill', $this->fieldLabel('ValidTill')),
            TagField::create('Groups', $this->fieldLabel('Groups'))
                ->setSource(Group::get())
                ->setShouldLazyLoad(true),
            TagField::create('TicketPages', $this->fieldLabel('TicketPages'))
                ->setSource($this->getTicketPageOptions())
                ->setShouldLazyLoad(true),
            CheckboxSetField::create('TicketType', $this->fieldLabel('TicketType'))
                ->setSource($this->ticketTypes())
        ]);

        return $fields;
    }

    public function friendlyEnum($enum)
    {
        return array_map(function ($value) use ($enum) {
            $fallback = is_numeric($value) ? $value : ucfirst(strtolower($value));
            return _t(__CLASS__ . ".{$enum}_{$value}", $fallback);
        }, $this->dbObject($enum)->enumValues());
    }

    public function ticketTypes()
    {
        $ticketTypes = ClassInfo::subclassesFor(Buyable::class);
        $ticketTypes = array_combine($ticketTypes, $ticketTypes);
        return array_map(fn($class) => singleton($class)->i18n_singular_name(), $ticketTypes);
    }

    public function getTicketPageOptions()
    {
        $ticketPageIds = Ticket::get()->column('TicketPageID');
        $ticketPages = [];
        if (!empty($ticketPageIds)) {
            $ticketPages = SiteTree::get()->filter(['ID' => $ticketPageIds]);
        }
        return $ticketPages;
    }

    /**
     * Return the table title
     *
     * @return string
     */
    public function getTableTitle()
    {
        return $this->i18n_singular_name();
    }

    /**
     * Calculate the discount
     *
     * @param float $total
     * @param Reservation $reservation
     */
    public function updateTotal(&$total, Reservation $reservation)
    {
        switch ($this->DiscountType) {
            case self::PERCENTAGE:
                $percentage = max(0, min(100, (float) $this->Amount));
                $discount = ($total / 100 * $percentage);
                $total -= $discount;
                $total = $total > 0 ? $total : 0;
                break;
            default:
                // case price
                // A Percentage always get's calculated over all tickets
                $discount = $this->AppliesTo === self::APPLIES_EACH_TICKET
                    ? $this->Amount * $reservation->Attendees()->count()
                    : $this->Amount;
                $total -= $discount;
                $total = $total > 0 ? $total : 0;
                break;
        }

        // save the modification on the join
        $this->setPriceModification($discount);
    }

    public function validateOncePerEmail(Reservation $reservation, ?string $email = null)
    {
        if (!$this->OncePerEmail) {
            return true;
        }

        // Reservation has no Email field: the address lives on the main contact (or comes from the form being
        // submitted). Without an address we can't check, so refuse.
        $email = $email ?: ($reservation->MainContact()->exists() ? $reservation->MainContact()->getEmail() : null);
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return false;
        }

        foreach ($this->usedReservations($reservation) as $used) {
            $usedEmail = $used->MainContact()->exists() ? strtolower(trim((string) $used->MainContact()->getEmail())) : '';
            if ($usedEmail === $email) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reservations that count as a use: paid, or pending payment (not abandoned carts or failed payments).
     */
    public function usedReservations(?Reservation $exclude = null)
    {
        $list = $this->Reservations()->filter(['Status' => [Reservation::STATUS_PAID, Reservation::STATUS_PENDING]]);
        if ($exclude && $exclude->ID) {
            $list = $list->exclude('ID', $exclude->ID);
        }
        return $list;
    }

    public function validateTicketType(Reservation $reservation)
    {
        if (!$this->TicketType) {
            return true;
        }

        $allowedTypes = json_decode($this->TicketType ?? '', true);
        return $reservation->OrderItems()->filter(['Buyable.ClassName' => $allowedTypes])->exists();
    }

    /**
     * Check if the discount exceeded the maximum uses
     *
     * @return bool
     */
    public function validateUses(?Reservation $reservation = null)
    {
        $uses = (int) $this->Uses;
        if ($uses < 0) {
            return true; // unlimited
        }

        // Strictly fewer uses than allowed (was "<=": usable Uses+1 times), counting paid/pending reservations only
        return $this->usedReservations($reservation)->count() < $uses;
    }

    /**
     * Check if the from and till dates are in the past and future
     *
     * @return bool
     */
    public function validateDate()
    {
        $now = DBDatetime::now()->getTimestamp();

        // Both bounds must hold (the till-check used to overwrite the from-check)
        if (!empty($this->ValidFrom) && strtotime($this->ValidFrom) > $now) {
            return false;
        }

        if (!empty($this->ValidTill)) {
            $till = strtotime($this->ValidTill);
            // A date without a time means "valid through that day"
            if (date('H:i:s', $till) === '00:00:00') {
                $till += 86399;
            }
            if ($till < $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the given member with the allowed groups
     *
     * @param Member $member
     *
     * @return bool
     */
    public function validateGroups(?Member $member = null)
    {
        if (!$this->Groups()->exists()) {
            return true;
        }

        if (empty($member)) {
            return false;
        } else {
            $validGroups = $this->Groups()->column('ID');
            $groupMembers = Member::get()->filter('Groups.ID:ExactMatchMulti', $validGroups);
            return (bool)$groupMembers->find('ID', $member->ID);
        }
    }

    /**
     * Validate if the given event is in the group of allowed events
     *
     * @param $event
     *
     * @return bool
     */
    public function validateEvents($event)
    {
        if (!$this->TicketPages()->exists()) {
            return true;
        }

        if (empty($event)) {
            return false;
        } else {
            $validEvents = $this->TicketPages()->column('ID');
            return in_array($event->ID, $validEvents);
        }
    }

    /**
     * Generate a unique coupon code
     *
     * @return string
     */
    public function generateCode()
    {
        // Random, not time based (uniqid() codes were guessable)
        do {
            $code = strtoupper(bin2hex(random_bytes(5)));
        } while (self::get()->filter('Code', $code)->exists());

        return $code;
    }
}
