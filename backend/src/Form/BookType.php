<?php

namespace App\Form;

use App\Entity\Book;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class BookType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('author', TextType::class, ['empty_data' => ''])
            ->add('title', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Le titre est requis.'),
                ],
            ])
            ->add('cover', TextType::class, ['empty_data' => ''])
            ->add('saga', TextType::class, ['empty_data' => ''])
            ->add('tome', TextType::class, ['empty_data' => ''])
            ->add('runtime', TextType::class, ['empty_data' => ''])
            ->add('scraper', TextType::class, ['empty_data' => ''])
            ->add('scrap_id', TextType::class, ['empty_data' => ''])

            ->add('narrators', TextType::class, [
                'mapped' => false,
                'required' => false,
                'empty_data' => '',
            ])
            ->add('ratings', TextType::class, [
                'mapped' => false,
                'required' => false,
                'empty_data' => '',
            ])

            ->add('file', FileType::class, [
                'mapped' => false,
                'required' => true,

                'constraints' => [
                    new File(
                        extensions: ['zip'],
                        extensionsMessage: 'Please upload a valid ZIP file',
                        mimeTypes: [
                            'application/zip',
                            'application/octet-stream',
                            'application/x-zip-compressed',
                            'multipart/x-zip',
                        ],
                        mimeTypesMessage: 'Please upload a valid ZIP file',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Book::class,
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
