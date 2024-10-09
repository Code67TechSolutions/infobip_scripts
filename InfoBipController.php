<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Api\V1\Infobip\EmailMessageOutbound;
use App\Models\Api\V1\Infobip\SmsMessage;
use App\Models\Api\V1\Infobip\WhatsAppMessageInbound;
use App\Models\Api\V1\Infobip\WhatsAppMessageOutbound;
use App\Models\Api\V1\Membership\Member;
use Exception;
use HTTP_Request2;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class InfoBipController extends Controller
{
    public function getInfoBipMessageInfo(Request $request)
    {
        error_log('getInfoBipMessageInfo START');
        $member_fk = $request->query('member_id');
        error_log($member_fk);
        $smsMessages = SmsMessage::where('member_id_fk', $member_fk)->get();
        return response()->json($smsMessages);
    }

    /**
     * Sends an SMS message using the provided request data.
     *
     * @param Request $request The request object containing 'to', 'msg', and 'member_id' query parameters.
     * @throws Exception If phone number, message, or member ID is missing.
     * @return JsonResponse JSON response containing the result of the SMS sending process.
     */

    public function sendSMS(Request $request)
    {
        $phoneNumber = $request->query('to');
        $message = $request->query('msg');
        $member_id_fk = null;
        $member_id_fk = $request->query('member_id');

        if (!$phoneNumber) {
            error_log($phoneNumber);
            return response()->json(['error' => 'Please add a phone number'], 422);
        }
        if (!$message) {
            error_log($message);
            return response()->json(['error' => 'Please add a message'], 422);
        }
        if (!$member_id_fk) {
            error_log($member_id_fk);
            return response()->json(['error' => 'Please add a member_id_fk'], 422);
        }

        $response = $this->sendMessage($phoneNumber, $message);

        $info_bip = new SmsMessage();
        $info_bip->recipient_number = $phoneNumber;
        $info_bip->message_id = $response['response']['messages'][0]['messageId'] ?? null;
        $info_bip->status_description = $response['response']['messages'][0]['status']['description'] ?? null;
        $info_bip->status_group_id = $response['response']['messages'][0]['status']['groupId'] ?? null;
        $info_bip->status_group_name = $response['response']['messages'][0]['status']['groupName'] ?? null;
        $info_bip->status_id = $response['response']['messages'][0]['status']['id'] ?? null;
        $info_bip->status_name = $response['response']['messages'][0]['status']['name'] ?? null;
        $info_bip->member_id_fk = $member_id_fk;
        $info_bip->to = $phoneNumber;
        $info_bip->message = $message;
        $info_bip->save();

        return response()->json($response);
    }

    /**
     * Sends an SMS message to a given phone number using the InfoBip API.
     *
     * @param string $phoneNumber The phone number to send the message to.
     * @param string $message The message to send.
     * @throws Exception If there is an error with the cURL request.
     * @return array An array containing the message sent, the response from the API, and the HTTP code.
     */

    private function sendMessage($phoneNumber, $message)
    {

        $payload = [
            'messages' => [
                [
                    'from' => 'ServiceSMS',
                    'destinations' => [
                        ['to' => $phoneNumber],
                    ],
                    'text' => $message,
                ],
            ],
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . config('app.info_bip.sms_api_key'),
        ];

        error_log('Sending SMS to: ' . $phoneNumber);
        error_log('SMS message: ' . $message);

        $curl = curl_init(config('app.info_bip.sms_url'));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_errno($curl)) {
            $error_msg = curl_error($curl);
            error_log('cURL error: ' . $error_msg);
        }

        curl_close($curl);

        $decodedResponse = json_decode($response, true);

        if ($httpCode != 200) {
            error_log('Failed to send SMS. HTTP Code: ' . $httpCode);
            error_log('Response: ' . $response);
        }

        return [
            'msg' => 'SES SMS sent',
            'response' => $decodedResponse,
            'http_code' => $httpCode,
        ];
    }

    public function updateMemberIdsFromWhatsAppNumbers()
    {
        error_log('START updating member IDs from WhatsApp numbers...');
        try {
            DB::transaction(function () {
                // Fetch all inbound messages where member_id_fk is null
                $inboundMessages = DB::table('whatsapp_messages_inbound')
                    ->whereNull('member_id_fk') // Only fetch messages where member_id_fk is null
                    ->get();

                error_log('Found ' . count($inboundMessages) . ' inbound messages with null member_id_fk...');

                if ($inboundMessages->isEmpty()) {
                    error_log('No inbound messages to update.');
                    return;
                }

                // Fetch all members and index by phone number
                $members = DB::table('members')
                    ->select('member_id', 'work_number', 'mobile_number', 'whatsapp_number')
                    ->get();

                error_log('Found ' . count($members) . ' members...');

                foreach ($inboundMessages as $message) {
                    $matchingMember = $members->first(function ($member) use ($message) {
                        return $member->work_number === $message->sender ||
                        $member->mobile_number === $message->sender ||
                        $member->whatsapp_number === $message->sender;
                    });

                    if ($matchingMember) {
                        error_log('Match found: ' . json_encode($matchingMember));
                        //  **  Update the member_id_fk ONLY  if the sender matches a phone number in members
                        DB::table('whatsapp_messages_inbound')
                            ->where('id', $message->id)
                            ->update(['member_id_fk' => $matchingMember->member_id]); // Update member_id_fk

                        error_log('Successfully updated Whatsapp message with member_id_fk to: 96996 ' . $matchingMember->member_id);
                    }
                }
            });

            return response()->json(['message' => 'Successfully updated Whatsapp Inbound messages with member_ids.'], 202);

        } catch (\Exception $e) {
            error_log('Error updating WhatsApp numbers: ' . $e->getMessage());

            return response()->json(['message' => 'Error updating WhatsApp numbers. =>' . $e], 500);
        }
    }
    public function getMemberWhatsAppMessagesv2(Request $request)
    {
        error_log('Handling getMemberWhatsAppMessagesv2 START');

        try {
            $member_id = $request->query('member_id');
            error_log('Member_id :' . $member_id);

            $inboundMessages = DB::table('whatsapp_messages_inbound')
                ->select('message as message', 'created_at as timestamp', DB::raw("'inbound' as direction"))
                ->where('member_id_fk', $member_id);

            $outboundMessages = DB::table('whatsapp_messages_outbound')
                ->select('message as message', 'created_at as timestamp', DB::raw("'outbound' as direction"))
                ->where('member_id_fk', $member_id);

            $messages = $inboundMessages
                ->unionAll($outboundMessages)
                ->orderBy('timestamp', 'desc')
                ->get();

            return response()->json($messages, 200);
        } catch (\Exception $e) {
            error_log('An error occurred: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred'], 500);
        }
    }

    public function getWhatsAppLogs(Request $request)
    {
        try {
            $member_id = $request->query('member_id');
            error_log('Member_id :' . $member_id);

            $inboundMessages = DB::table('whatsapp_messages_inbound')
                ->select('message as message', 'created_at as timestamp', 'member_id_fk as member_id', 'sender as destination', 'channel as status', 'received_at as seen_at', DB::raw("'inbound' as direction"));

            $outboundMessages = DB::table('whatsapp_messages_outbound')
                ->select('message as message', 'created_at as timestamp', 'member_id_fk as member_id', 'to as destination', 'status_description as status', 'seen_at as seen_at', DB::raw("'outbound' as direction"));

            $messages = $inboundMessages
                ->unionAll($outboundMessages)
                ->orderBy('timestamp', 'desc')
                ->get();

            return response()->json($messages, 200);
        } catch (\Exception $e) {
            error_log('An error occurred: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred'], 500);
        }
    }

    /**
     * Retrieves WhatsApp messages for a specific member.
     *
     * @param Request $request The HTTP request object containing the member ID.
     * @return \Illuminate\Http\JsonResponse The JSON response containing the WhatsApp messages for the member.
     *                                      If the member ID is not provided, returns a 400 Bad Request response.
     * Endpoint_getWhatsAppMessages => 127.0.0.1:8000/api/v1/infobip/whatsapp/messages
     */

    public function getMemberWhatsAppMessages(Request $request)
    {
        try {
            // Retrieve the member_id from the request query parameters
            $member_fk = $request->query('member_id');

            // Log the member_id
            error_log('Member ID: ' . $member_fk);

            // Validate the member_id
            if (empty($member_fk)) {
                error_log('Member ID is missing');
                return response()->json(['error' => 'Member ID is required'], 400);
            }

            // Fetch WhatsApp messages for the given member ID
            $WhatsAppMessages = WhatsAppMessageOutbound::where('member_id_fk', $member_fk)->get();

            // $WhatsAppMessages = WhatsAppMessageInbound::where('member_id_fk', $member_fk)->get();
            // Check if messages are found
            if ($WhatsAppMessages->isEmpty()) {
                error_log('No messages found for member ID: ' . $member_fk);
                return response()->json(['message' => 'No messages found'], 404);
            }

            // Return the fetched messages as JSON
            return response()->json($WhatsAppMessages, 200);
            //return Response::json($WhatsAppMessages, 200);

        } catch (\Exception $e) {
            // Log the error
            error_log('Error fetching WhatsApp messages: ' . $e->getMessage());

            // Return a JSON response with the error message
            return response()->json(['error' => 'An error occurred while fetching messages'], 500);
        }

    }

    /**
     * Handles the retrievingof a WhatsApp message.
     *
     * @param Request $request The HTTP request object containing the necessary parameters.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response containing the result of the WhatsApp message sending process.
     *  Endpoint GetAll WhatsApp Inbound messages => 127.0.0.1:8000/api/v1/infobip/whatsapp/history/inbound
     *  Endpoint GetAll WhatsApp Outbound messages => 127.0.0.1:8000/api/v1/infobip/whatsapp/history/outbound
     */
    public function getAllWhatsAppOutbound(Request $request)
    {
        error_log('Handling getAllWhatsAppOutbound START');
        try {
            $whatsAppMessages = WhatsAppMessageOutbound::all();
            return response()->json($whatsAppMessages, 200);
        } catch (\Exception $e) {
            echo $e;
            return response()->json(['error' => 'An error occurred while retrieving WhatsApp Outbound messages'], 500);
        }
    }

    public function getAllWhatsAppInbound(Request $request)
    {
        error_log('Handling getAllWhatsAppInbound START');
        try {
            $whatsAppMessages = WhatsAppMessageInbound::all();
            return response()->json($whatsAppMessages, 200);
        } catch (\Exception $e) {
            echo $e;
            return response()->json(['error' => 'An error occurred while retrieving WhatsApp Inbound messages'], 500);
        }
    }

    public function getAllWhatsAppInboundUnregistered(Request $request)
    {
        error_log('Handling getAllWhatsAppInbound Unregistered START');
        try {
            $whatsAppMessages = WhatsAppMessageInbound::where('member_id_fk', null)->get();
            return response()->json($whatsAppMessages, 200);
        } catch (\Exception $e) {
            echo $e;
            return response()->json(['error' => 'An error occurred while retrieving WhatsApp Inbound Unregistered messages'], 500);
        }
    }

    /**
     * Handles the sending of a WhatsApp message using the provided request data.
     *
     * @param Request $request The HTTP request object containing the necessary parameters.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response containing the result of the WhatsApp message sending process.
     *  Endpoint_sendWhatsApp  TemplateMessage => 127.0.0.1:8000/api/v1/infobip/whatsapp/send/template
     *  Endpoint_sendWhatsApp  TextMessage => 127.0.0.1:8000/api/v1/infobip/whatsapp/send/text
     */

    public function handleWhatsAppMessage(Request $request)
    {
        error_log('Sending WhatsApp message...');

        $phoneNumber = $request->input('messages.0.to', $request->input('to'));
        $memberIdFk = $request->input('member_id', 1345);
        $template = $request['messages'][0]['content']['templateName'] ?? null;
        $text = $template ? null : $request['content.text'];

        error_log('WhatApp Number :' . $phoneNumber);
        error_log('Member ID : ' . $memberIdFk);
        error_log('TemplateName : ' . ($template ?? 'No SES_template was provided'));
        error_log('TextContent : ' . ($text ?? 'No SES_text content was provided.'));

        if (!$phoneNumber) {
            return response()->json(['error' => 'Please add a phone number'], 422);
        }

        if (!$template && !$text) {
            return response()->json(['error' => 'Please add a template or text content'], 422);
        }

        if (!$memberIdFk) {
            return response()->json(['error' => 'Please add a member_id_fk'], 422);
        }

        $response = $this->sendWhatsAppMessage($phoneNumber, $template, $text, );
        $this->saveWhatsAppMessageInfo($response, $phoneNumber, $memberIdFk, $template ?? $text);

        return response()->json($response);
    }

    /**
     * Sends a WhatsApp message to the specified phone number using the provided template or text.
     *
     * @param string $phoneNumber The phone number to send the message to.
     * @param string $template The name of the template to use for the message. If null, the text parameter will be used.
     * @param string $text The text content of the message. If null, the template parameter will be used.
     *
     * @throws Exception If there is an error with the cURL request.
     *
     * @return array The response from the API, containing the message sent and the HTTP code.
     */

    private function sendWhatsAppMessage($phoneNumber, $template, $text)
    {

        $url = config('app.info_bip.whatsapp_url') . ($template ? 'template' : 'text');
        $notifyUrl = config('app.host.app_url') . config('app.info_bip.whatsapp_notify_url');
        error_log('NotifyURL (whatsapp) : ' . $notifyUrl);
        error_log('Whatsapp_URL : ' . $url);
        $serviceNumber = config('app.info_bip.whatsapp_service_number');
        $messageId = Uuid::uuid4()->toString();
        error_log('WhatsApp Message Id : ' . $messageId);

        $headers = [
            'Authorization: ' . config('app.info_bip.whatsapp_api_key'),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($template) {
            $data = [
                'messages' => [
                    [
                        'from' => $serviceNumber,
                        'to' => $phoneNumber,
                        'messageId' => $messageId,
                        'content' => $this->buildMessageContent($template, $text),
                        'callbackData' => 'Callback data',
                        'notifyUrl' => $notifyUrl,
                        'urlOptions' => [
                            'shortenUrl' => true,
                            'trackClicks' => false,
                        ],
                    ],
                ],
            ];
        }

        if ($text) {
            $data = [
                'from' => $serviceNumber,
                'to' => $phoneNumber,
                'messageId' => $messageId,
                'content' => $this->buildMessageContent($template, $text),
                'callbackData' => 'Callback data',
                'notifyUrl' => $notifyUrl,
                'urlOptions' => [
                    'shortenUrl' => true,
                    'trackClicks' => false,
                ],
            ];
        }

        $response = $this->makeCurlRequest($url, json_encode($data), $headers);

        if ($response['status'] != 200) {
            throw new Exception('Unexpected HTTP status: ' . $response['status']);
        }

        return $response['body'];
    }

    /**
     * Builds the content for a WhatsApp message based on the provided template and text.
     *
     * @param string $template The name of the template to use for the message. If null, the text parameter will be used.
     * @param string $text The text content of the message. If null, the template parameter will be used.
     *
     * @return array An associative array representing the content of the WhatsApp message.
     *               If a template is provided, the array will contain 'templateName', 'templateData', and 'language'.
     *               If text is provided, the array will contain 'text'.
     */

    private function buildMessageContent($template, $text)
    {
        if ($template) {
            return [
                'templateName' => $template,
                'templateData' => [
                    'body' => [
                        'placeholders' => ['SES_dev'],
                    ],
                ],
                'language' => 'en',

            ];
        } else if ($text) {
            return [
                'text' => $text,
            ];
        }
    }

    /**
     * Makes a cURL request to the specified URL with the provided data and headers.
     *
     * @param string $url The URL to send the request to.
     * @param string $data The data to send in the request body.
     * @param array $headers The headers to include in the request.
     *
     * @return array An associative array containing the response body and HTTP status code.
     *               The 'body' key contains the response body as an associative array,
     *               and the 'status' key contains the HTTP status code.
     */

    private function makeCurlRequest($url, $data, $headers)
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [
            'body' => is_string($response) ? json_decode($response, true) : null,
            'status' => $status,
        ];
    }

    /**
     * Saves WhatsApp message information to the database.
     *
     * @param mixed $response The response data from the WhatsApp message sending.
     * @param string $phoneNumber The phone number to which the message was sent.
     * @param int $memberIdFk The foreign key of the member receiving the message.
     * @param string $message The content of the message sent.
     * @param mixed $messageId The ID of the message being saved.
     */

    private function saveWhatsAppMessageInfo($response, $phoneNumber, $memberIdFk, $message)
    {
        $infoBip = new WhatsAppMessageOutbound();
        $infoBip->message_id = $response['messages'][0]['messageId'] ?? $response['messageId'];
        $infoBip->status_description = $response['messages'][0]['status']['description'] ?? 'nil';
        $infoBip->status_group_id = $response['messages'][0]['status']['groupId'] ?? '0';
        $infoBip->status_group_name = $response['messages'][0]['status']['groupName'] ?? 'nil';
        $infoBip->status_id = $response['messages'][0]['status']['id'] ?? 0;
        $infoBip->status_name = $response['messages'][0]['status']['name'] ?? 0;
        $infoBip->member_id_fk = $memberIdFk;
        $infoBip->to = $phoneNumber;
        $infoBip->message = $message;
        $infoBip->save();
    }

    /**
     * Handles the WhatsApp report received from the request.
     *
     * @param Request $request The HTTP request object.
     * @throws \Exception If an error occurs while processing the request.
     * @return \Illuminate\Http\JsonResponse The JSON response indicating the success or failure of the delivery report.
     * Endopoint_ WhatApp outbound reports => 127.0.0.1:8000/api/v1/infobip/whatsapp/reports/outbound
     */

    public function handleWhatsAppOutbound(Request $request)
    {

        try {
            error_log('Handling WhatsApp Outgoing message delivery report...');

            $decodedBody = json_decode($request->getContent(), true);
            if (isset($decodedBody['results']) && is_array($decodedBody['results'])) {
                foreach ($decodedBody['results'] as $result) {
                    $messageId = $result['messageId'];
                    if (isset($messageId)) {
                        error_log('Message ID: ' . $messageId);
                        $WaMessage = WhatsAppMessageOutbound::where('message_id', $messageId)->first();
                        if ($WaMessage && isset($result['status']['name'])) {

                            $WaMessage->status_name = $result['status']['name'];
                            $WaMessage->status_description = $result['status']['description'];
                            $WaMessage->status_group_name = $result['status']['groupName'];
                            $WaMessage->status_group_id = $result['status']['groupId'];
                            $WaMessage->status_id = $result['status']['id'];
                            $WaMessage->delivery_report = $result;
                            $WaMessage->save();
                            error_log('Message STATUS saved to database!');
                        } elseif ($WaMessage && isset($result['seenAt'])) {
                            $seen_at = $result['seenAt'];
                            $sent_at = $result['sentAt'];
                            $WaMessage->seen_report = $result;
                            $WaMessage->seen_at = $seen_at;
                            $WaMessage->sent_at = $sent_at;
                            $WaMessage->save();
                            error_log('Message STATUS saved to database!');
                        } else {
                            error_log('No WhatsApp message found for message ID: ' . $messageId);
                        }
                    }

                    if (isset($result['status']['name'])) {
                        error_log('Delivery status : ' . $result['status']['name']);
                    }
                    if (isset($result['to'])) {
                        error_log('WhatApp Number : ' . $result['to']);
                    }
                    if (isset($result['channel'])) {
                        error_log('Channel: ' . $result['channel']);
                    }
                }
            } else {
                error_log('Results key not found or not an array in the decoded body.');
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return response()->json(['message' => 'Delivery report received unsuccessfully.'], 400);
        }

        return response()->json(['message' => 'Delivery report received successfully.'], 200);
    }

    /**
     * Handles the incoming WhatsApp messages and saves them to the database.
     *
     * @param Request $request The HTTP request object containing the incoming message data.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response indicating the success or failure of the message handling.
     *
     * @throws \Exception If an error occurs while processing the request.
     *
     * Endpoint_WhatApp inbound messages => 127.0.0.1:8000/api/v1/infobip/whatsapp/messages/inbound
     */

    public function handleWhatsAppInbound(Request $request)
    {
        try {
            $decodedBody = json_decode($request->getContent(), true);
            error_log('Handling WhatsApp Inbound message...');

            if (isset($decodedBody['results']) && is_array($decodedBody['results'])) {
                foreach ($decodedBody['results'] as $result) {
                    $sender = $result['sender'];
                    $messageId = $result['messageId'];
                    $received_at = $result['receivedAt'];
                    $type = isset($result['content'][0]['type']) ? $result['content'][0]['type'] : "Unknown_Content";
                    $message = isset($result['content'][0]['text']) && strtolower($type) == 'text' ? $result['content'][0]['cleanText'] : null;
                    error_log('WhatsApp Sender Number: ' . $sender);
                    error_log('ReceivedAt: ' . $received_at);
                    error_log('Message Id: ' . $messageId);
                    $member_id_fk = null;
                    try {
                        $user = Member::where('mobile_number', 'like', '%' . $sender . '%')
                            ->orWhere('whatsapp_number', 'like', '%' . $sender . '%')
                            ->first();
                        if ($user) {
                            $member_id_fk = $user->member_id;
                            error_log('Inbound whatsapp message from Member id :' . $member_id_fk);
                        } else {
                            error_log('Inbound whatsapp message number does not exists (not a member)');
                        }

                    } catch (\Throwable $th) {
                        error_log('Inbound whatsapp message number does not exists (not a member) :' . $th);
                    }

                    $whatsappMessageInbound = WhatsAppMessageInbound::create([
                        'event' => $result['event'],
                        'sender' => $sender,
                        'message' => $message ?? $type,
                        'message_type' => $type ?? 'Unknown_Content',
                        'destination' => $result['destination'],
                        'channel' => $result['channel'],
                        'received_at' => $received_at,
                        'message_id' => $result['messageId'],
                        'paired_message_id' => $messageId,
                        'callback_data' => $result['callbackData'],
                        'content' => json_encode($result['content']),
                        'member_id_fk' => $member_id_fk,
                        'report' => $result,

                    ]);

                    if ($whatsappMessageInbound) {
                        error_log('WhatsApp Inbound message saved to database!');
                    } else {
                        error_log('Failed to save WhatsApp inbound message to database!');
                    }
                }
            } else {
                error_log('Results key not found or not an array in the decoded body.');
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return response()->json(['message' => 'Inbound message received unsuccessfully.'], 400);
        }

        return response()->json(['message' => 'Inbound message received successfully.'], 200);

    }

/**
 * Retrieves email messages sent by a specific member from the database.
 *
 * @param Request $request The incoming HTTP request containing the member ID.
 *
 * @return \Illuminate\Http\JsonResponse The JSON response containing the email messages sent by the member.
 *
 * @throws \Illuminate\Database\QueryException If there is an error querying the database.
 *
 * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the member ID is not provided in the request.
 */
    public function getMemberEmails(Request $request)
    {
        error_log('getMemberEmailMessages START');
        $member_fk = $request->query('member_id');
        if (empty($member_fk)) {
            return response()->json(['error' => 'Member ID is required'], 400);
        }

        $EmMessages = EmailMessageOutbound::where('member_id_fk', $member_fk)->get();
        return response()->json($EmMessages, 200);

    }

/**
 * Handles the incoming email requests and sends emails using Infobip's email API.
 *
 * @param Request $req The incoming HTTP request containing the email data.
 *
 * @return void
 *
 * @throws HTTP_Request2_Exception If an error occurs while sending the email.
 * POST Endpont => 127.0.0.1:8000/api/v1/infobip/email/send/mail
 */

    public function handleEmail(Request $req)
    {
        $request = new HTTP_Request2();
        $decodedBody = json_decode($req->getContent(), true);
        $from = $decodedBody['from'] ?? 'No_mail';
        $to = $decodedBody['to'] ?? 'No_mail';
        $subject = $decodedBody['subject'] ?? 'No_subject';
        $text = $decodedBody['text'] ?? 'No_text';
        $html = $decodedBody['html'] ?? null;
        $placeholders = $decodedBody['placeholders'] ?? [];
        $member_id = $decodedBody['member_id'] ?? null;

        if (!$member_id) {
            error_log('No member_id provided in request');
            return;
        }

        error_log('Sending email to : ' . $to);
        $request->setUrl(config('app.info_bip.email_url'));
        $request->setMethod(HTTP_Request2::METHOD_POST);
        $request->setConfig(array(
            'follow_redirects' => true,
        ));

        $request->setHeader(array(
            'Authorization' => 'Basic ' . config('app.info_bip.email_api_key'),
            'Content-Type' => 'multipart/form-data',
            'Accept' => 'application/json',
        ));

        $from = config('app.info_bip.email_from');
        $bulkId = 'customBulkId';

        $placeholders = '{"ph1": "Success", "ph2": "Example"}';
        $notifyUrl = config('app.host.app_url') . '/api/v1/infobip/email/reports/outbound';
        error_log('Notify URL (email) : ' . $notifyUrl);
        $request->addPostParameter(array(
            'from' => 'SES SUPPORT<' . $from . '>',
            'subject' => $subject,
            'to' => $to,
            'text' => $text,
            'html' => $html,
            'bulkId' => $bulkId,
            'intermediateReport' => 'true',
            'defaultPlaceholders' => $placeholders,
            'notifyUrl' => $notifyUrl,
            'notifyContentType' => 'application/json',
            'callbackData' => 'DLR callback data',
        ));
        try {
            $response = $request->send();
            if ($response->getStatus() == 200) {
                $body = $response->getBody();
                $decodedBody = json_decode($body, true);
                $bulkId = $decodedBody['bulkId'] ?? 'nope';
                $messages = $decodedBody['messages'] ?? [];
                if ($messages) {
                    foreach ($messages as $message) {
                        $message_id = $message['messageId'] ?? 'nil';

                        $emailMessageOutbound = EmailMessageOutbound::create([
                            'bulk_id' => $bulkId,
                            'messages' => $text,
                            'to' => $to,
                            'message_id' => $message_id,
                            'status' => json_encode($message['status']),
                            'status_group_id' => $message['status']['groupId'],
                            'status_group_name' => $message['status']['groupName'],
                            'status_id' => $message['status']['id'],
                            'status_name' => $message['status']['name'],
                            'status_description' => $message['status']['description'],
                            'member_id_fk' => $member_id,
                            'report' => json_encode($message),
                        ]);
                        error_log('Saved email to databse');

                    }

                }

            } else {
                echo 'Unexpected HTTP status: ' . $response->getStatus() . ' ' .
                $response->getReasonPhrase();
            }
        } catch (HTTP_Request2_Exception $e) {
            echo 'Error: ' . $e->getMessage();
        }

    }

/**
 * Handles the incoming email delivery report from Infobip.
 *
 * @param Request $request The incoming HTTP request containing the delivery report.
 *
 * @return \Illuminate\Http\JsonResponse The JSON response indicating the success or failure of the report handling.
 *
 * @throws \Exception If an error occurs while processing the request.
 * POST Endpoint => 127.0.0.1:8000/api/v1/infobip/email/reports
 */

    public function handleEmailOutbound(Request $request)
    {
        try {
            error_log('Handling Email Outgoing message delivery report...');

            $decodedBody = json_decode($request->getContent(), true);
            if (isset($decodedBody['results']) && is_array($decodedBody['results'])) {
                foreach ($decodedBody['results'] as $result) {
                    $messageId = $result['messageId'];
                    if (isset($messageId)) {
                        error_log('Message ID: ' . $messageId);
                        $EmMessage = EmailMessageOutbound::where('message_id', $messageId)->first();
                        if ($EmMessage && isset($result['status']['name'])) {

                            $EmMessage->status_name = $result['status']['name'];
                            $EmMessage->status_description = $result['status']['description'];
                            $EmMessage->status_group_name = $result['status']['groupName'];
                            $EmMessage->status_group_id = $result['status']['groupId'];
                            $EmMessage->status_id = $result['status']['id'];
                            $EmMessage->status = json_encode($result['status']);
                            $EmMessage->member_id_fk = 1345;
                            $EmMessage->report = $result;
                            $EmMessage->save();
                            error_log('Message STATUS saved to database!');
                        } else {
                            error_log('No Email message found for message ID: ' . $messageId);
                        }
                    }

                    if (isset($result['status']['name'])) {
                        error_log('Delivery STATUS : ' . $result['status']['name']);
                    }
                    if (isset($result['to'])) {
                        error_log('Email : ' . $result['to']);
                    }
                    if (isset($result['channel'])) {
                        error_log('Channel: ' . $result['channel']);
                    }
                }
            } else {
                error_log('Results key not found or not an array in the decoded body.');
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return response()->json(['message' => 'Delivery report received unsuccessfully.'], 400);
        }

        return response()->json(['message' => 'Delivery report received successfully.'], 200);
    }

    public function renewalNotification(Request $request)
    {
        try {
            error_log('Membership renewalNotification');
            $mobile_number = $request->mobile_number;
            $email = $request->email;
            // $member_id = $request->member_id;

            if ($mobile_number) {
                error_log('Sending Renewal Notification to Whatsapp number message to: ' . $mobile_number);
                $response = $this->sendWhatsAppMessage($mobile_number, $template, $text);
                $this->saveWhatsAppMessageInfo($response, $mobile_number, $member_id, $template ?? $text);
                return response()->json('Renewal WhatsApp notification sent successfully', 200);
            } elseif ($email) {
                error_log('Sending Renewal Notification to Email: ' . $email);
                $this->handleEmail($request);
                return response()->json('Renewal Email notification sent successfully', 200);
            } else {
                error_log('No mobile number or email provided for renewal notification');
                return response()->json('No mobile number or email provided for renewal notification', 400);
            }
        } catch (\Exception $e) {
            error_log('Error in renewalNotification: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send renewal notification'], 500);
        }
    }
}
