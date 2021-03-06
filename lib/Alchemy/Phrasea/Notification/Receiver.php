<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Notification;

use Alchemy\Phrasea\Exception\InvalidArgumentException;
use Alchemy\Phrasea\Model\Entities\User;

class Receiver implements ReceiverInterface
{
    private $name;
    private $email;

    public function __construct($name, $email)
    {
        if (!\Swift_Validate::email($email)) {
            throw new InvalidArgumentException(sprintf('Invalid e-mail address (%s)', $email));
        }

        $this->name = $name;
        $this->email = $email;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Creates a Receiver given a User
     *
     * @param User $user
     *
     * @return Receiver
     *
     * @throws InvalidArgumentException In case no valid email is found for user
     */
    public static function fromUser(User $user)
    {
        return new static($user->getDisplayName(), $user->getEmail());
    }
}
