<?php

/**
 * Copyright Talisman Innovations Ltd. (2016). All rights reserved
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package     qpush-bundle
 * @copyright   Talisman Innovations Ltd. (2016)
 * @license     Apache License, Version 2.0
 */

namespace Uecode\Bundle\QPushBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection as ArrayCollection;
use Talisman\TideBundle\Interfaces\TenantInterface;
use Talisman\TideBundle\Interfaces\TransactionInterface;
use Talisman\TideBundle\Traits\TenantTrait;
use Talisman\TideBundle\Traits\TransactionTrait;
use Talisman\TideBundle\Traits\IdTrait;

/**
 * @ORM\Entity
 * @ORM\Table(name="uecode_qpush_message",
 * options={"collate":"	utf8mb4_unicode_ci", "charset":"utf8mb4", "row_format":"COMPRESSED"},
 * indexes={@ORM\Index(name="uecode_qpush__tenant_queue_created_idx",columns={"tenant_id","queue","created"}),
 *          @ORM\Index(name="uecode_qpush_delivered_idx",columns={"delivered"}),
 *          @ORM\Index(name="uecode_qpush_message_idx",columns={"message"}, flags={"fulltext"}),
 *          @ORM\Index(name="uecode_qpush_transaction_id_idx", columns={"transaction_id"}),
 *         })
 */
class DoctrineMessage implements TenantInterface, TransactionInterface {

    use TenantTrait;
    use TransactionTrait;
    use IdTrait;

    /**
     * @var \DateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $created;

    /**
     * @var \DateTime $updated
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private $updated;

    /**
     *
     * @ORM\Column(type="string")
     */
    private $queue;

    /**
     *
     * @ORM\Column(type="boolean")
     */
    private $delivered;

    /**
     *
     * @ORM\Column(type="array")
     */
    private $message;

    /**
     * @ORM\Column(type="integer")
     */
    private $length;

    /**
     * @ORM\OneToMany(targetEntity="DoctrineMessageResult", mappedBy="message")
     */
    private $results;

    /*
     * Constructor
     */

    public function __construct() {
        $this->results = new ArrayCollection();
    }

    public function getCreated() {
        return $this->created;
    }

    public function getUpdated() {
        return $this->updated;
    }

    function getQueue() {
        return $this->queue;
    }

    public function getDelivered() {
        return $this->delivered;
    }

    public function getMessage() {
        return $this->message;
    }

    public function getLength() {
        return $this->length;
    }

    public function getResults() {
        return $this->results;
    }

    public function setCreated(\DateTime $created) {
        $this->created = $created;
        return $this;
    }

    public function setUpdated(\DateTime $updated) {
        $this->updated = $updated;
        return $this;
    }

    public function setQueue($queue) {
        $this->queue = $queue;
        return $this;
    }

    public function setDelivered($delivered) {
        $this->delivered = $delivered;
        return $this;
    }

    public function setMessage($message) {
        $this->message = $message;
        return $this;
    }

    public function setLength($length) {
        $this->length = $length;
        return $this;
    }

    public function setResults($results) {
        $this->results = $results;
        return $this;
    }

}
