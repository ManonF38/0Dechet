<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Comment;
use App\Entity\Rate;
use App\Entity\Recipe;
use App\Entity\SubCategory;
use App\Entity\Type;
use App\Entity\User;
use App\Form\CommentType;
use App\Form\DeleteType;
use App\Form\RecipeType;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use App\Services\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use WhiteOctober\BreadcrumbsBundle\Model\BreadCrumbs;


/**
* @Route("/recette", name="recipe_")
*/
class RecipeController extends AbstractController
{
    /**
     * Method to display all the recipes in template/recipe/browse.html.twig
     * @Route("/", name="browse", methods={"GET"})
     */
    public function browse(RecipeRepository $recipeRepository, Request $request, PaginatorInterface $paginator): Response
    {
        $recipes = $paginator->paginate(  // add paginator
            $recipeRepository->findAll(),   // query to display all the recipes
            $request->query->getInt('page', 1), // number of the current page in the Url, if only one = 1
            15,    // number of results in a page
        ); 

        // If number of pagination exist we return the view
        if (!empty($recipes->getItems())) {
        return $this->render('recipe/browse.html.twig', [
            'recipes' => $recipes,
            'title' => 'Toutes les recettes',
        ]);
          
        } else { // if number of pagination does not exist in URL we throw a 404
            throw $this->createNotFoundException('Pas de recette'); 
        }    
      
    }

    /**
     * Method to display results for research done in the nav search bar
     * Submit the search bar form will redirect on this route
     * @Route("/recherche", name="search", methods={"GET"})
     */
    public function search(RecipeRepository $recipeRepository, Request $request, PaginatorInterface $paginator): Response
    {
        
        //We recuperate the data send in the url by the search form
        $q = $request->query->get('search');
        //Then put it in our customQuery
        $recipes = $paginator->paginate(   // add paginator
                $recipeRepository->findAllWithSearch($q),  // query to display all the recipes of the search results
                $request->query->getInt('page', 1),   // number of the current page in the Url, if only one = 1
                15, // number of results in a page
        );

        // If number of pagination exist we return the view
        if (!empty($recipes->getItems())) {
        return $this->render('recipe/search.html.twig', [
            'recipes' => $recipes,
            'title' => 'Résultat pour "'.$q .'"',
        ]);
        } else { // if number of pagination does not exist in URL we throw a 404
            throw $this->createNotFoundException('Pas de recette'); 
        }    
       
    }

     /**
     * Method for add a new recipe. Send a form, receive the response and flush to the Database
     * @IsGranted("IS_AUTHENTICATED_FULLY")
     * @Route("/ajout", name="add", methods={"GET","POST"})
     */
    public function add(Request $request, MailerInterface $mailer, FileUploader $fileUploader)
    {

        $recipe = new Recipe;

        $form = $this->createForm(RecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recipe->setStatus(1);
            $recipe->setUser($this->getUser());

            // We use a Services to move and rename the file
            $newName = $fileUploader->saveFile($form['image'], 'assets/images/recipes');
            $recipe->setImage($newName);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($recipe);
            $entityManager->flush();

            $email = (new Email())
            ->from('0dechet.project@gmail.com')
            ->to($recipe->getUser()->getEmail())
            ->subject('Bienvenue sur 0dechet')
            
            ->text('Heureux de vous compter parmis nos membres '.$recipe->getUser()->getUsername().'');
    
            $mailer->send($email);
            $this->addFlash(
                'success',
                'Votre recette a été ajoutée, un mail de confirmation vous a été envoyé'
            );

            return $this->redirectToRoute('recipe_show', [
                'slug' => $recipe->getSlug(),
                ]);
        }

        return $this->render('recipe/add.html.twig', [
            'recipe' => $recipe,
            'form' => $form->createView(),
            'title' => "Ajouter une recette",
        ]);
    }

        /**
     * Method to edit an existing recipe. Send a form, receive the response and flush to the Database
     * @IsGranted("IS_AUTHENTICATED_FULLY")
     * @Route("/edition/{slug}", name="edit", methods={"GET","POST"})
     */
    public function edit(Recipe $recipe, Request $request, FileUploader $fileUploader)
    {
        $this->denyAccessUnlessGranted('EDIT', $recipe);

        $image = $recipe->getImage();
        
        $form = $this->createForm(RecipeType::class, $recipe);
        
        $form->handleRequest($request);


        if ($form->isSubmitted() && $form->isValid()) {

            // We use a Services to move and rename the file
            $newName = $fileUploader->saveFile($form['image'], 'assets/images/recipes');
            $recipe->setImage($newName);
            // If user don't edit the image we let the old image
            if ($recipe->getImage() === null){
                $recipe->setImage($image);
            }

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->flush();
            $this->addFlash(
                'success',
                'Votre recette a été modifié'
            );

            return $this->redirectToRoute('recipe_show', [
                'slug' => $recipe->getSlug(),
                ]);
        }

        $formDelete = $this->createForm(DeleteType::class, null, [
            'action' => $this->generateUrl('recipe_delete', ['id' => $recipe->getId()])
        ]);

        return $this->render('recipe/edit.html.twig', [
            'recipe' => $recipe,
            'form' => $form->createView(),
            'deleteForm' => $formDelete->createView(),
            'title' => "Modifier une recette",
        ]);
    }
  
    /**
     *  Method to display all information about a recipe in template/recipe/show.html.twig
     * @Route("/{slug}", name="show", methods={"GET", "POST"})
     */
  
    public function show(Recipe $recipe, RecipeRepository $recipeRepository, Request $request, EntityManagerInterface $em): Response
    {
        // Comment Form
        $comment = new Comment();
        $commentForm = $this->createForm(CommentType::class, $comment);
        $commentForm->handleRequest($request);

        if ($_POST) {
            if ($commentForm->isSubmitted() && $commentForm->isValid()) {
                // Recipe linked to the comment
                $comment->setRecipe($recipe);

                $comment->setStatus(1);
          
                $comment->setUser($this->getUser());

                $em = $this->getDoctrine()->getManager();
                // Cette fois on persiste le genre car c'est un nouvel objet
                $em->persist($comment);
                $em->flush();

                $this->addFlash(
                    'success',
                    'Votre commentaire a été ajouté'
                );

                return $this->redirectToRoute('recipe_show', [
                'slug' => $recipe->getSlug(),
                ]);
            }
      
            //Homemadeadmin/?entity=User&action=list&menuIndex=6&submenuIndex=-1 RateForm
            else {
                $rate = new Rate();
                $rating = $_POST['difficulty'];
                $rate->setRate($rating);
                $rate->setRecipe($recipe);
                $rate->setUser($this->getUser());

                $entityManager = $this->getDoctrine()->getManager();
                $entityManager->persist($rate);
                $entityManager->flush();

                $this->addFlash(
                    'success',
                    'Votre note a été prise en compte'
                );

                return $this->redirectToRoute('recipe_show', [
                'slug' => $recipe->getSlug(),
                ]);
            }
        }
        
            return $this->render('recipe/show.html.twig', [
            'recipe' => $recipe,
            'title' => $recipe->getName(),
            'commentForm' => $commentForm->createView(),
        ]);

    }
        
     /**
     *  Method to display the recipes by Categories in the template category.html.twig from the directory recipe
     * @Route("/categorie/{slug}", name="browseByCategory")
     */
    public function browseByCategory(Category $category, PaginatorInterface $paginator, Request $request)
    {
        $categoryRepository = $this->getDoctrine()->getRepository(Category::class)->findBy([],['createdAt' => 'desc']);

        $recipes = $paginator->paginate(   // add paginator
            $categoryRepository,  // query to display all the recipes by category
            $request->query->getInt('page', 1),   // number of the current page in the Url, if only one = 1
            15, // number of results in a page
        );

        // If number of pagination exist we return the view
        if (!empty($recipes->getItems())) {
            return $this->render('recipe/category.html.twig', [
                'recipes' => $recipes,
                'category' => $category,
                'title' => $category->getName(),
            ]);
        } else { // if number of pagination does not exist in URL we throw a 404
            throw $this->createNotFoundException('Pas de recette'); 
        }    
    }
          
     /**
     * Method to display the recipes by Sub Categories in the template subCategory.html.twig from the directory recipe
     * @Route("/sous-categorie/{slug}", name="browseBySubCategory")
     */
    public function browseBySubCategory(SubCategory $subCategory, PaginatorInterface $paginator, Request $request)
    {
        $subCategoryRepository = $this->getDoctrine()->getRepository(SubCategory::class)->findBy([],['createdAt' => 'desc']);

        $recipes = $paginator->paginate(   // add paginator
            $subCategoryRepository,  // query to display all the recipes by subCategory
            $request->query->getInt('page', 1),   // number of the current page in the Url, if only one = 1
            15, // number of results in a page
        );

        // If number of pagination exist we return the view
        if (!empty($recipes->getItems())) {
        return $this->render('recipe/subCategory.html.twig', [
            'recipes' => $recipes,
            'subCategory' => $subCategory,
            'title' => $subCategory->getName(),
        ]);
        } else { // if number of pagination does not exist in URL we throw a 404
            throw $this->createNotFoundException('Pas de recette'); 
        }   
        
    }

    /**
     * Method to display the recipes by Types in the template type.html.twig from the directory recipe
     * @Route("/type/{slug}", name="browseByType")
     */
    public function browseByType(Type $type, RecipeRepository $recipeRepository, PaginatorInterface $paginator, Request $request)
    {

        $typeRepository = $this->getDoctrine()->getRepository(SubCategory::class)->findBy([],['createdAt' => 'desc']);


        $recipes = $paginator->paginate(   // add paginator
            $typeRepository,  // query to display all the recipes by type
            $request->query->getInt('page', 1),   // number of the current page in the Url, if only one = 1
            15, // number of results in a page
        );

        // If number of pagination exist we return the view
        if (!empty($recipes->getItems())) {
        return $this->render('recipe/type.html.twig', [
            'recipes' => $recipes,
            'type' => $type,
            'title' => $type->getName(),
        ]);
        } else { // if number of pagination does not exist in URL we throw a 404
            throw $this->createNotFoundException('Pas de recette'); 
        }   
            
    }

    /**
     * Method to allow a user to delete one of his/her recipe off the website
     * @IsGranted("IS_AUTHENTICATED_FULLY")
     * @Route("/recipe/suppression/{id}", name="delete", methods={"DELETE"}, requirements={"id": "\d+"})
     */
    public function delete(EntityManagerInterface $em, Request $request, Recipe $recipe)
    {
        $this->denyAccessUnlessGranted('DELETE', $recipe);

        $formDelete = $this->createForm(DeleteType::class);
        $formDelete->handleRequest($request);

        if ($formDelete->isSubmitted() && $formDelete->isValid()) {
            $em->remove($recipe);
            $em->flush();

            $this->addFlash(
                'success',
                'La recette a bien été supprimé.'
            );

            return $this->redirectToRoute('user_read', [
                'id' => $this->getUser()->getId(),
            ]);
        }

        return $this->redirectToRoute('recipe_edit', [
            'slug' => $recipe->getslug(),
        ]);
    }

}
