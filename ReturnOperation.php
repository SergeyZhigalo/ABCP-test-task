<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;
use NW\WebService\References\Operations\Notification\Contractor;
use NW\WebService\References\Operations\Notification\Seller;
use NW\WebService\References\Operations\Notification\Employee;
use NW\WebService\References\Operations\Notification\Status;
use NW\WebService\References\Operations\Notification\ReferencesOperation;
use NW\WebService\References\Operations\Notification\NotificationEvents;

/*
    Резюме по коду
    Код предназначен для обработки операций возврата товаров и отправки уведомлений сотрудникам и клиентам по электронной почте и SMS.
    Качество кода:
    Оригинальная версия: Некорректное приведение типов, дублирующийся код, неиспользование зависимостей, низкая читаемость.
    Отрефакторенная версия: Устранены проблемы с типами, выделены вспомогательные методы, повышена читаемость и модульность кода.
*/

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $data = $this->getRequest('data');
        $resellerId = (int)($data['resellerId'] ?? 0);
        $notificationType = (int)($data['notificationType'] ?? 0);
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        if ($resellerId) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            return $result;
        }

        if ($notificationType) {
            $this->throwException('Empty notificationType');
        }

        $reseller = $this->getSellerById((int)($data['creator'] ?? 0));
        if (is_null($reseller)) {
            $this->throwException('Seller not found!');
        }

        $client = $this->getContractorById((int)($data['clientId'] ?? 0));
        $isCustomerClient = $client && $client->type !== Contractor::TYPE_CUSTOMER;
        if ($isCustomerClient || $client->Seller->id !== $resellerId) {
            throw new Exception('Client not found!', 400);
        }

        $clientFullName = $client->getFullName() ?: $client->name;

        $creator = $this->getEmployeeById((int)($data['creator'] ?? 0));
        if (is_null($creator)) {
            $this->throwException('Creator not found!');
        }

        $expert = $this->getEmployeeById((int)($data['expertId'] ?? 0));
        if (is_null($expert)) {
            $this->throwException('Expert not found!');
        }

        $differences = $this->getDifferencesMessage(
            notificationType: $notificationType,
            data: $data,
            resellerId: $resellerId
        );

        $templateData = $this->prepareTemplateData(
            data: $data,
            creator: $creator,
            expert: $expert,
            clientFullName: $clientFullName,
            differences: $differences
        );

        $this->validateTemplateData($templateData);

        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if (!empty($emailFrom) && count($emails) > 0) {
            $this->sendEmployeeNotifications($emails, $emailFrom, $templateData, $resellerId);
            $result['notificationEmployeeByEmail'] = true;
        }

        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            $this->sendClientNotifications(
                client: $client,
                emailFrom: $emailFrom,
                templateData: $templateData,
                resellerId: $resellerId,
                data: $data,
                result: $result
            );
        }

        return $result;
    }

    private function getEmployeeById(int $id): ?Contractor
    {
        return Employee::getById($id);
    }

    private function getSellerById(int $id): ?Contractor
    {
        return Seller::getById($id);
    }

    private function getContractorById(int $id): ?Contractor
    {
        return Contractor::getById($id);
    }

    /**
     * @throws Exception
     */
    private function throwException(string $message, int $statusCode = 400): void
    {
        throw new Exception($message, $statusCode);
    }

    private function getDifferencesMessage(int $notificationType, array $data, int $resellerId): string
    {
        if ($notificationType === self::TYPE_NEW) {
            return __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO'   => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }
        return '';
    }

    private function prepareTemplateData(array $data, Contractor $creator, Contractor $expert, string $clientFullName, string $differences): array
    {
        return [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$data['complaintNumber'],
            'CREATOR_ID'         => (int)$data['creatorId'],
            'CREATOR_NAME'       => $creator->getFullName(),
            'EXPERT_ID'          => (int)$data['expertId'],
            'EXPERT_NAME'        => $expert->getFullName(),
            'CLIENT_ID'          => (int)$data['clientId'],
            'CLIENT_NAME'        => $clientFullName,
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$data['agreementNumber'],
            'DATE'               => (string)$data['date'],
            'DIFFERENCES'        => $differences,
        ];
    }

    /**
     * @throws Exception
     */
    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $value) {
            if (empty($value)) {
                throw new Exception("Template Data ($key) is empty!", 500);
            }
        }
    }

    private function sendEmployeeNotifications(array $emails, string $emailFrom, array $templateData, int $resellerId): void
    {
        $body = [];
        foreach ($emails as $email) {
            $body[] = [
                'emailFrom' => $emailFrom,
                'emailTo'   => $email,
                'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
            ];
        }

        $this->sendMessage(
            body: $body,
            resellerId: $resellerId,
            status: NotificationEvents::CHANGE_RETURN_STATUS,
        );
    }

    private function sendClientNotifications(Contractor $client, string $emailFrom, array $templateData, int $resellerId, array $data, array &$result): void
    {
        if (!empty($emailFrom) && !empty($client->email)) {
            $this->sendMessage(
                body: [
                    'emailFrom' => $emailFrom,
                    'emailTo'   => $client->email,
                    'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                    'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                ],
                resellerId: $resellerId,
                status: NotificationEvents::CHANGE_RETURN_STATUS,
                clientId: $client->id,
                differencesTo: (int)$data['differences']['to']
            );
            $result['notificationClientByEmail'] = true;
        }

        if (!empty($client->mobile)) {
            $error = '';

            $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);

            if ($res) {
                $result['notificationClientBySms']['isSent'] = true;
            }

            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        }
    }

    private function sendMessage(
        array $body,
        string $resellerId,
        int $status,
        int $clientId = 0,
        int $differencesTo = 0
    ): void
    {
        MessagesClient::sendMessage($body, $resellerId, $status, $clientId, $differencesTo);
    }
}
