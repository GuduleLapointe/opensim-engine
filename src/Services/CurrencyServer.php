<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Economy\Currency;

/**
 * What the viewers and the simulators ask of the currency of a grid (XML-RPC, `currency.php` of its economy) and of the land
 * tools (`landtool.php`): quote and buy currency, the balance of an avatar, the transfers of the regions, the purchase of land. The
 * methods and the fields are the ones of the viewers and of the money modules; an avatar and a region prove who they are with the
 * secure session and the secret the simulator holds.
 *
 *     $server = new CurrencyServer($currency, $ip);
 *     $currencyCalls = new XmlRpc($server->currencyMethods());
 *     $landCalls = new XmlRpc($server->landMethods());
 */
final class CurrencyServer
{
    /**
     * @param string $ip the address of whoever calls: a quote is tied to it
     */
    public function __construct(private readonly Currency $currency, private readonly string $ip) {}

    /** The calls the money is served for, by the name of the method. */
    public function currencyMethods(): array
    {
        $this->requireKey();

        return [
            'getCurrencyQuote' => $this->quote(...),
            'buyCurrency' => $this->buy(...),
            'simulatorUserBalanceRequest' => $this->balance(...),
            'regionMoveMoney' => $this->moveMoney(...),
            'simulatorClaimUserRequest' => $this->claimUser(...),
        ];
    }

    /** The calls of the land tools, by the name of the method. */
    public function landMethods(): array
    {
        $this->requireKey();

        return ['preflightBuyLandPrep' => $this->prepareLand(...), 'buyLandPrep' => $this->buyLand(...)];
    }

    /** Money is not served without the key: a bad configuration, said to whoever asks. */
    private function requireKey(): void
    {
        $this->currency->settings->hasKey() ||
            throw new Failure(
                'Bad configuration: the currency is not served without its key (MoneyScriptAccessKey, [MoneyServer] of MoneyServer.ini)',
                503,
            );
    }

    /**
     * The viewer asks the cost of an amount of currency.
     *
     * @param array<string,mixed> $request agentId, secureSessionId, currencyBuy
     * @return array<string,mixed>
     */
    private function quote(array $request): array
    {
        if (
            !$this->currency->checkSecureSession($request['agentId'] ?? null, null, $request['secureSessionId'] ?? null)
        ) {
            return $this->unable("Unable to Authenticate\n\nClick URL for more info.");
        }

        return [
            'success' => true,
            'currency' => $this->currency->quote($request['currencyBuy'] ?? 0),
            'confirm' => $this->currency->confirmValue($this->ip),
        ];
    }

    /**
     * The viewer buys currency.
     *
     * @param array<string,mixed> $request agentId, secureSessionId, currencyBuy, confirm
     * @return array<string,mixed>
     */
    private function buy(array $request): array
    {
        $agent = $request['agentId'] ?? null;
        $session = $request['secureSessionId'] ?? null;
        $amount = $request['currencyBuy'] ?? 0;
        if (($request['confirm'] ?? null) != $this->currency->confirmValue($this->ip)) {
            return $this->unable("\n\nMissmatch Confirm Value!!");
        }
        if (!$this->currency->checkSecureSession($agent, null, $session)) {
            return $this->unable("\n\nMissmatch Secure Session ID!!");
        }
        $bought =
            $this->currency->processTransaction($agent, $this->currency->virtualToReal($amount), $this->ip) &&
            !empty($this->currency->addMoney($agent, $amount, $session)['success']);

        // The module of the currency says what to do when the purchase cannot be done here
        return $bought ? ['success' => true] : ['success' => false] + $this->currency->refusal((string) $agent);
    }

    /**
     * A region asks the balance of an avatar.
     *
     * @param array<string,mixed> $request agentId, secureSessionId
     * @return array<string,mixed>
     */
    private function balance(array $request): array
    {
        $agent = $request['agentId'] ?? null;
        $session = $request['secureSessionId'] ?? null;
        // The funds of an avatar are for the one who has its secure session (the simulator where it is)
        $balance = $this->currency->checkSecureSession($agent, null, $session)
            ? $this->currency->balance($agent, $session)
            : -1;
        if ($balance < 0) {
            return $this->unable('Could not authenticate your avatar. Money operations may be unavailable', ' ');
        }

        return ['success' => true, 'agentId' => $agent, 'funds' => $balance];
    }

    /**
     * A region moves money from an avatar to another.
     *
     * @param array<string,mixed> $request agentId, destId, secureSessionId, regionId, secret, cash, transactionType, flags,
     *        aggregatePermInventory, aggregatePermNextOwner, description
     * @return array<string,mixed>
     */
    private function moveMoney(array $request): array
    {
        $agent = $request['agentId'] ?? null;
        $dest = $request['destId'] ?? null;
        $session = $request['secureSessionId'] ?? null;
        $region = $request['regionId'] ?? null;
        $cash = $request['cash'] ?? 0;
        if (!$this->currency->checkRegionSecret($region, $request['secret'] ?? null)) {
            return $this->unable('This region is not authorized to manage your money.', ' ');
        }
        if (!$this->currency->checkSecureSession($agent, $region, $session)) {
            return $this->unable('Unable to authenticate avatar. Money operations may be unavailable', ' ');
        }
        $balance = $this->currency->balance($agent, $session);
        if ($balance < $cash) {
            return $this->unable('You do not have sufficient funds for this purchase', ' ');
        }
        $this->currency->moveMoney(
            $agent,
            $dest,
            $cash,
            $request['transactionType'] ?? 0,
            $request['flags'] ?? 0,
            $request['description'] ?? '',
            $request['aggregatePermInventory'] ?? 0,
            $request['aggregatePermNextOwner'] ?? 0,
            $this->ip,
        );
        $this->currency->updateSimulatorBalance($agent, $this->currency->balance($agent, $session), $session);
        $this->currency->updateSimulatorBalance($dest, $this->currency->balance($dest));

        return [
            'success' => true,
            'agentId' => $agent,
            'funds' => $balance,
            'funds2' => $balance,
            'currencySecret' => ' ',
        ];
    }

    /**
     * A region claims an avatar: it is the one they are on now.
     *
     * @param array<string,mixed> $request agentId, secureSessionId, regionId, secret
     * @return array<string,mixed>
     */
    private function claimUser(array $request): array
    {
        $agent = $request['agentId'] ?? null;
        $session = $request['secureSessionId'] ?? null;
        $region = $request['regionId'] ?? null;
        if (!$this->currency->checkRegionSecret($region, $request['secret'] ?? null)) {
            return $this->unable('This region is not authorized to manage your money.', ' ');
        }
        if (!$this->currency->checkSecureSession($agent, null, $session)) {
            return $this->unable('Unable to authenticate avatar. Money operations may be unavailable.', ' ');
        }
        if (!$this->currency->setCurrentRegion($agent, $region)) {
            return $this->unable('Error occurred, when DB was updated.', ' ');
        }

        return [
            'success' => true,
            'agentId' => $agent,
            'funds' => $this->currency->balance($agent, $session),
            'currencySecret' => ' ',
        ];
    }

    /**
     * The viewer asks what buying land would cost, before it does.
     *
     * @param array<string,mixed> $request agentId, secureSessionId, currencyBuy, billableArea
     * @return array<string,mixed>
     */
    private function prepareLand(array $request): array
    {
        if (
            !$this->currency->checkSecureSession($request['agentId'] ?? null, null, $request['secureSessionId'] ?? null)
        ) {
            return $this->unable("Unable to Authenticate\n\nClick URL for more info.");
        }
        $help = $this->currency->settings->helperUrl;

        return [
            'success' => true,
            'currency' => ['estimatedCost' => $this->currency->virtualToReal($request['currencyBuy'] ?? 0)],
            'membership' => [
                'upgrade' => false,
                'action' => $help,
                'levels' => [
                    'levels' => ['id' => '00000000-0000-0000-0000-000000000000', 'description' => 'some level'],
                ],
            ],
            'landUse' => ['upgrade' => false, 'action' => $help],
            'confirm' => $this->currency->confirmValue($this->ip),
        ];
    }

    /**
     * The viewer buys the currency it lacks for land; the land itself is paid afterwards, by the region.
     *
     * @param array<string,mixed> $request agentId, secureSessionId, currencyBuy, estimatedCost, billableArea, confirm
     * @return array<string,mixed>
     */
    private function buyLand(array $request): array
    {
        $agent = $request['agentId'] ?? null;
        $session = $request['secureSessionId'] ?? null;
        $amount = $request['currencyBuy'] ?? 0;
        if (($request['confirm'] ?? null) != $this->currency->confirmValue($this->ip)) {
            return $this->unable("\n\nMissmatch Confirm Value!!");
        }
        if (!$this->currency->checkSecureSession($agent, null, $session)) {
            return $this->unable("\n\nUnable to Authenticate\n\nClick URL for more info.");
        }
        if (!is_numeric($amount) || $amount < 0) {
            return $this->unable("\n\nInvalid amount");
        }
        $cost = $request['estimatedCost'] ?? 0;
        $cost = $cost ?: $this->currency->virtualToReal($amount);
        if (!$this->currency->processTransaction($agent, $cost, $this->ip)) {
            return $this->unable(
                "\n\nThe gateway has declined your transaction. Please update your payment method AND try again later.",
            );
        }
        if (empty($this->currency->addMoney($agent, $amount, $session)['success'])) {
            return $this->unable("\n\nYou do not have sufficient funds for this purchase");
        }
        $this->currency->updateSimulatorBalance($agent, -1, $session);

        return ['success' => true];
    }

    /**
     * What is told when a call cannot be answered: the message and the page the viewer shows.
     *
     * @return array{success:false,errorMessage:string,errorURI:string}
     */
    private function unable(string $message, ?string $uri = null): array
    {
        return [
            'success' => false,
            'errorMessage' => $message,
            'errorURI' => $uri ?? $this->currency->settings->helperUrl,
        ];
    }
}
