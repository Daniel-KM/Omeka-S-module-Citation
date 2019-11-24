<?php
namespace Bibliography\Service\Form;

use Bibliography\Form\SiteSettingsFieldset;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class SiteSettingsFieldsetFactory implements FactoryInterface
{
    use TraitCslData;

    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $fieldset = new SiteSettingsFieldset(null, $options);
        return $fieldset
            ->setCitationStyles($this->getCitationStyles())
            ->setCitationLocales($this->getCitationLocales());
    }
}
