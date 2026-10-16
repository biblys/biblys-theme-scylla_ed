<?php

use Biblys\Service\CurrentSite;
use Biblys\Service\Images\ImagesService;
use Model\AlertQuery;
use Model\Article;
use Model\ArticleQuery;
use Model\ArticleTagQuery;
use Model\AwardQuery;
use Model\FileQuery;
use Model\InvitationsArticlesQuery;
use Model\LinkQuery;
use Model\RoleQuery;
use Model\SpecialOfferQuery;
use Model\StockQuery;
use Model\WishQuery;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

const CLEANUP_BATCH_SIZE = 25;
const CLEANUP_PUBLISHER_ID = 262; // Éditions Dystopia
const CLEANUP_TYPE_ID = 2; // Livres numériques

/**
 * Liste, puis supprime par lots, les livres numériques des éditions Dystopia
 * qui n'ont aucun exemplaire (donc jamais vendus : les exemplaires vendus
 * restent en base).
 * Pour chaque article, supprime aussi ses fichiers numériques (base et disque),
 * son image de couverture et ses données liées (contributeurs, liens, prix,
 * étiquettes, envies, alertes, invitations).
 */
return function (
    Request $request,
    CurrentSite $currentSite,
    ImagesService $imagesService,
): Response {
    $request->attributes->set("page_title", "Nettoyage");

    $articles = ArticleQuery::create()
        ->filterForCurrentSite($currentSite)
        ->filterByPublisherId(CLEANUP_PUBLISHER_ID)
        ->filterByTypeId(CLEANUP_TYPE_ID)
        ->orderByTitle()
        ->find();

    $deletable = [];
    $inLibraries = [];
    foreach ($articles as $article) {
        $libraryCopies = StockQuery::create()
            ->filterByArticle($article)
            ->where("Stock.UserId IS NOT NULL")
            ->count();
        if ($libraryCopies > 0) {
            $inLibraries[] = ["article" => $article, "copies" => $libraryCopies];
            continue;
        }

        if (StockQuery::create()->filterByArticle($article)->exists()) {
            continue;
        }
        if (SpecialOfferQuery::create()->filterByFreeArticleId($article->getId())->exists()) {
            continue;
        }
        $deletable[] = $article;
    }

    $report = "";
    if ($request->getMethod() === "POST") {
        $action = $request->request->get("action");

        if ($action === "delete") {
            $filesystem = new Filesystem();
            $deleted = [];
            foreach (array_slice($deletable, 0, CLEANUP_BATCH_SIZE) as $article) {
                deleteArticleWithRelatedData($article, $imagesService, $filesystem);
                $deleted[] = $article->getTitle();
            }
            $deletable = array_slice($deletable, CLEANUP_BATCH_SIZE);

            $report = "<p class='alert alert-success'>" . count($deleted) . " article(s) supprimé(s) : "
                . htmlspecialchars(implode(", ", $deleted)) . ".</p>";
        }

        if ($action === "unavailable") {
            $updated = [];
            foreach ($inLibraries as $entry) {
                $article = $entry["article"];
                if ($article->getAvailabilityDilicom() === Article::AVAILABILITY_OUT_OF_PRINT) {
                    continue;
                }
                $article->setAvailabilityDilicom(Article::AVAILABILITY_OUT_OF_PRINT);
                $article->save();
                $updated[] = $article->getTitle();
            }

            $report = "<p class='alert alert-success'>" . count($updated) . " article(s) passé(s) en épuisé : "
                . htmlspecialchars(implode(", ", $updated)) . ".</p>";
        }
    }

    $deletableRows = "";
    foreach ($deletable as $article) {
        $fileCount = FileQuery::create()->filterByArticleId($article->getId())->count();
        $deletableRows .= "<tr><td>{$article->getId()}</td><td>" . articleLink($article)
            . "</td><td>{$article->getEan()}</td><td>$fileCount</td></tr>";
    }

    $deletableCount = count($deletable);
    $batchSize = min($deletableCount, CLEANUP_BATCH_SIZE);
    $deleteForm = $deletableCount > 0 ? "
        <form method='post' class='fieldset'>
            <input type='hidden' name='action' value='delete'>
            <p><strong>Toute suppression est définitive.</strong> Sauvegardez la base avant de continuer.</p>
            <button type='submit' class='btn btn-danger'>Supprimer les $batchSize premiers articles</button>
        </form>" : "<p>Aucun article à supprimer.</p>";

    $libraryRows = "";
    $toMakeUnavailableCount = 0;
    foreach ($inLibraries as $entry) {
        $article = $entry["article"];
        $isOutOfPrint = $article->getAvailabilityDilicom() === Article::AVAILABILITY_OUT_OF_PRINT;
        if (!$isOutOfPrint) {
            $toMakeUnavailableCount++;
        }
        $status = $isOutOfPrint ? "Épuisé" : "<strong>Disponible</strong>";
        $libraryRows .= "<tr><td>{$article->getId()}</td><td>" . articleLink($article)
            . "</td><td>{$article->getEan()}</td><td>{$entry["copies"]}</td><td>$status</td></tr>";
    }

    $unavailableForm = $toMakeUnavailableCount > 0 ? "
        <form method='post' class='fieldset'>
            <input type='hidden' name='action' value='unavailable'>
            <button type='submit' class='btn btn-warning'>Passer $toMakeUnavailableCount article(s) en épuisé</button>
        </form>" : "<p>Tous ces articles sont déjà indisponibles.</p>";

    $libraryCount = count($inLibraries);

    return new Response("
        <h1>Nettoyage</h1>
        $report

        <h2>Articles numériques à supprimer</h2>
        <p>
            Ces livres numériques peuvent être supprimés car ils ne sont dans aucune
            bibliothèque d'utilisateur (aucun exemplaire n'a jamais été vendu ou offert).
        </p>
        <p>$deletableCount livre(s) numérique(s) sans exemplaire (hors articles offerts par une offre spéciale).</p>
        <table class='table'>
            <thead><tr><th>Id</th><th>Titre</th><th>EAN</th><th>Fichiers</th></tr></thead>
            <tbody>$deletableRows</tbody>
        </table>
        $deleteForm

        <h2 class='mt-5'>Articles numériques à passer en indisponible</h2>
        <p>
            Ces livres numériques sont dans au moins une bibliothèque d'utilisateur : ils ne peuvent
            donc pas être supprimés. Ils vont être passés en épuisé pour ne plus pouvoir être achetés.
        </p>
        <p>$libraryCount livre(s) numérique(s) présent(s) dans au moins une bibliothèque.</p>
        <table class='table'>
            <thead><tr><th>Id</th><th>Titre</th><th>EAN</th><th>Bibliothèques</th><th>Statut</th></tr></thead>
            <tbody>$libraryRows</tbody>
        </table>
        $unavailableForm
    ");
};

function articleLink(Article $article): string
{
    $url = htmlspecialchars("/a/{$article->getUrl()}");
    return "<a href='$url'>" . htmlspecialchars($article->getTitle()) . "</a>";
}

function deleteArticleWithRelatedData(
    Article $article,
    ImagesService $imagesService,
    Filesystem $filesystem,
): void {
    $article->preDelete();
    $articleId = $article->getId();

    foreach (FileQuery::create()->filterByArticleId($articleId)->find() as $file) {
        $filesystem->remove($file->getFullPath());
        $file->delete();
    }

    if ($imagesService->imageExistsFor($article)) {
        $imagesService->deleteImageFor($article);
    }

    RoleQuery::create()->filterByArticleId($articleId)->delete();
    LinkQuery::create()->filterByArticleId($articleId)->delete();
    AwardQuery::create()->filterByArticleId($articleId)->delete();
    ArticleTagQuery::create()->filterByArticleId($articleId)->delete();
    WishQuery::create()->filterByArticleId($articleId)->delete();
    AlertQuery::create()->filterByArticleId($articleId)->delete();
    InvitationsArticlesQuery::create()->filterByArticleId($articleId)->delete();

    $article->delete();
}
