<?php

declare(strict_types=1);


namespace EnjoysCMS\Module\Hybridauth;


use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Enjoys\Cookie\Exception;
use EnjoysCMS\Core\Components\Auth\Authorize;
use EnjoysCMS\Core\Components\Auth\Identity;
use EnjoysCMS\Core\Components\Helpers\Redirect;
use EnjoysCMS\Core\Entities\Group;
use EnjoysCMS\Core\Entities\User;
use Hybridauth\Exception\InvalidArgumentException;
use Hybridauth\Hybridauth;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


final class HybridauthApp
{
    public const ALLOW_METHODS = [
        'auth',
        'attach'
    ];

    public const ERROR_QUERY = 'oauth-error';

    private Hybridauth $hybridauth;
    private array $config;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        Config $config,
        private UrlGeneratorInterface $urlGenerator,
        private Authorize $authorize,
        private EntityManager $em,
        private Identity $identity
    ) {
        $this->config = $config->getModuleConfig()->asArray();

        $this->config['callback'] = $this->urlGenerator->generate(
            'hybridauth/callback',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->hybridauth = new Hybridauth($this->config);
    }


    public function getHybridauth(): Hybridauth
    {
        return $this->hybridauth;
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws OptimisticLockException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Throwable
     */
    public function auth(Data $data): void
    {
        try {
            $user = $this->getUser($data);

            if ($user === null) {
                if (false === ($this->config['allow-auto-register'] ?? true)) {
                    throw new \Exception('New register not allowed via another sites');
                }
                $user = $this->registerUser($data);
            }

            $this->authorize->setAuthorized($user, [
                'authenticate' => 'hybridauth'
            ]);

            Redirect::http(urldecode($data->getRedirectUrl()));
        } catch (\Throwable $e) {
            $this->authorize->logout();
            throw $e;
        }
    }

    public function attach(Data $data): void
    {
        try {
            /** @var Entities\Hybridauth|null $hybridauthData */
            $hybridauthData = $this->em->getRepository(Entities\Hybridauth::class)->findOneBy([
                'identifier' => $data->getIdentifier(),
                'provider' => $data->getProvider(),
            ]);

            if ($hybridauthData === null) {
                $hybridauthData = new Entities\Hybridauth();
                $hybridauthData->setIdentifier($data->getIdentifier());
                $hybridauthData->setProvider($data->getProvider());
            }
            $hybridauthData->setAvatar($data->getUserProfile()->photoURL);
            $hybridauthData->setProfileUrl($data->getUserProfile()->profileURL);

            $hybridauthData->setDisplayName(
                $data->getUserProfile()->displayName
                ?? $data->getUserProfile()->emailVerified
                ?? $data->getUserProfile()->email
                ?? $data->getUserProfile()->identifier
            );

            $hybridauthData->setUser($this->identity->getUser());

            $this->em->persist($hybridauthData);
            $this->em->flush();

            Redirect::http(urldecode($data->getRedirectUrl()));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function getUser(Data $data): ?User
    {
        /** @var Entities\Hybridauth|null $hybridauthData */
        $hybridauthData = $this->em->getRepository(Entities\Hybridauth::class)->findOneBy([
            'identifier' => $data->getIdentifier(),
            'provider' => $data->getProvider(),
        ]);

        return $hybridauthData?->getUser();
    }

    /**
     * @throws OptimisticLockException
     * @throws ORMException
     */
    private function registerUser(Data $data): User
    {
        $userGroup = $this->em->getRepository(Group::class)->findOneBy(['name' => 'Users']);

        $user = new User();
        $user->setLogin(uniqid('user'));
        $user->setPasswordHash('');
        $user->setName($data->getUserProfile()->displayName ?? uniqid($data->getProvider()));
        $user->setEmail($data->getUserProfile()->email);
        $user->setGroups($userGroup);
        $this->em->persist($user);

        $hybridauthData = new Entities\Hybridauth();
        $hybridauthData->setIdentifier($data->getIdentifier());
        $hybridauthData->setProvider($data->getProvider());
        $hybridauthData->setAvatar($data->getUserProfile()->photoURL);
        $hybridauthData->setProfileUrl($data->getUserProfile()->profileURL);
        $hybridauthData->setDisplayName(
            $data->getUserProfile()->displayName
            ?? $data->getUserProfile()->emailVerified
            ?? $data->getUserProfile()->email
            ?? $data->getUserProfile()->identifier
        );

        $hybridauthData->setUser($user);

        $this->em->persist($hybridauthData);

        $this->em->flush();

        return $user;
    }

}
