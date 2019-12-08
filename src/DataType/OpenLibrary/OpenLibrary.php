<?php
namespace Bibliography\DataType\OpenLibrary;

use Bibliography\DataType\AbstractBibliographyDataType;
use Bibliography\Suggester\OpenLibrary\OpenLibrarySuggest;

class OpenLibrary extends AbstractBibliographyDataType
{
    const API = 'https://openlibrary.org/api/books';

    public function getSuggester()
    {
        /** @var \Omeka\Entity\Module $module */
        $module = $this->services->get('Omeka\ModuleManager')->getModule('Bibliography');

        /** @var \Zend\Http\Client $client */
        $client = $this->services->get('Omeka\HttpClient');
        $client->setUri(self::API);
        $client->getRequest()->getHeaders()
            ->addHeaderLine(
                'User-Agent',
                'Omeka-S-module-Bibliography/'
                    . $module->getIni('version')
                    . ' ('
                    . $module->getIni('module_link')
                    . ')')
            ->addHeaderLine('Accept', 'application/json')
        ;

        $citeProc = $this->prepareCiteProc();

        return new OpenLibrarySuggest($client, $citeProc, $this->options);
    }
}
