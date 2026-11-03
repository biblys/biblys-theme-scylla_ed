<?php

use Biblys\Service\CurrentSite;
use Biblys\Service\Images\ImagesService;
use Model\AlertQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Model\Article;
use Model\ArticleQuery;
use Model\ArticleTagQuery;
use Biblys\Data\ArticleType;
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
const CLEANUP_GOODIES_TYPE_ID = ArticleType::GOODIES;

/**
 * Nettoyage des articles des éditions Dystopia présents sur le site.
 *
 * Seuls sont supprimés les articles qui n'ont aucun exemplaire (donc jamais
 * vendus : les exemplaires vendus restent en base). Les articles liés à un
 * financement participatif (présents dans une contrepartie ou ayant un
 * exemplaire rattaché à une campagne) ne sont jamais supprimés.
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

    $crowdfundingIds = crowdfundingArticleIds();

    $articles = ArticleQuery::create()
        ->filterForCurrentSite($currentSite)
        ->filterByPublisherId(CLEANUP_PUBLISHER_ID)
        ->filterByTypeId(CLEANUP_TYPE_ID)
        ->orderByTitle()
        ->find();

    $deletable = [];
    $inLibraries = [];
    $keptForCrowdfunding = [];
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
        if (isset($crowdfundingIds[$article->getId()])) {
            $keptForCrowdfunding[] = $article;
            continue;
        }
        $deletable[] = $article;
    }

    $otherDeletable = [];
    $otherArticles = ArticleQuery::create()
        ->filterForCurrentSite($currentSite)
        ->filterByPublisherId(CLEANUP_PUBLISHER_ID)
        ->filterByTypeId(CLEANUP_TYPE_ID, Criteria::NOT_EQUAL)
        ->orderByTypeId()
        ->orderByTitle()
        ->find();
    $bindings = [];
    foreach ($otherArticles as $article) {
        $copiesCount = StockQuery::create()->filterByArticle($article)->count();
        if (preg_match("/reli[ée]e|Yirminadingrad/iu", $article->getTitle())) {
            $bindings[] = [
                "article" => $article,
                "copies" => $copiesCount,
                "onSale" => StockQuery::create()
                    ->filterByArticle($article)
                    ->filterBySellingDate(null, Criteria::ISNULL)
                    ->count(),
                "crowdfunding" => isset($crowdfundingIds[$article->getId()]),
            ];
        }

        if ($copiesCount > 0) {
            continue;
        }
        if (SpecialOfferQuery::create()->filterByFreeArticleId($article->getId())->exists()) {
            continue;
        }
        if (isset($crowdfundingIds[$article->getId()])) {
            $keptForCrowdfunding[] = $article;
            continue;
        }
        $otherDeletable[] = $article;
    }

    $goodies = [];
    $goodiesCopiesToDelete = 0;
    $goodiesArticles = ArticleQuery::create()
        ->filterForCurrentSite($currentSite)
        ->filterByPublisherId(CLEANUP_PUBLISHER_ID)
        ->filterByTypeId(CLEANUP_GOODIES_TYPE_ID)
        ->orderByTitle()
        ->find();
    foreach ($goodiesArticles as $article) {
        $total = StockQuery::create()->filterByArticle($article)->count();
        if ($total === 0) {
            continue;
        }
        $isCrowdfunding = isset($crowdfundingIds[$article->getId()]);
        $deletableCopies = $isCrowdfunding ? 0 : countDeletableCopies($article);
        $goodies[] = [
            "article" => $article,
            "total" => $total,
            "deletable" => $deletableCopies,
            "crowdfunding" => $isCrowdfunding,
        ];
        $goodiesCopiesToDelete += $deletableCopies;
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

        if ($action === "delete-others") {
            $filesystem = new Filesystem();
            $deleted = [];
            foreach (array_slice($otherDeletable, 0, CLEANUP_BATCH_SIZE) as $article) {
                deleteArticleWithRelatedData($article, $imagesService, $filesystem);
                $deleted[] = $article->getTitle();
            }
            $otherDeletable = array_slice($otherDeletable, CLEANUP_BATCH_SIZE);

            $report = "<p class='alert alert-success'>" . count($deleted) . " article(s) supprimé(s) : "
                . htmlspecialchars(implode(", ", $deleted)) . ".</p>";
        }

        if ($action === "delete-goodies-copies") {
            $deletedCopies = 0;
            foreach ($goodies as $index => $entry) {
                $deletedCopies += deleteDeletableCopies($entry["article"]);
                $goodies[$index]["total"] -= $entry["deletable"];
                $goodies[$index]["deletable"] = 0;
            }
            $goodiesCopiesToDelete = 0;

            $report = "<p class='alert alert-success'>$deletedCopies exemplaire(s) de goodies supprimé(s).</p>";
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
            <button type='submit' class='btn btn-danger'>Passer $toMakeUnavailableCount article(s) en épuisé</button>
        </form>" : "<p>Tous ces articles sont déjà indisponibles.</p>";

    $libraryCount = count($inLibraries);

    $otherRows = "";
    foreach ($otherDeletable as $article) {
        $otherRows .= "<tr><td>{$article->getId()}</td><td>" . articleLink($article)
            . "</td><td>{$article->getType()->getName()}</td><td>{$article->getEan()}</td></tr>";
    }
    $otherCount = count($otherDeletable);
    $otherBatchSize = min($otherCount, CLEANUP_BATCH_SIZE);
    $otherForm = $otherCount > 0 ? "
        <form method='post' class='fieldset'>
            <input type='hidden' name='action' value='delete-others'>
            <p><strong>Toute suppression est définitive.</strong> Sauvegardez la base avant de continuer.</p>
            <button type='submit' class='btn btn-danger'>Supprimer les $otherBatchSize premiers articles</button>
        </form>" : "<p>Aucun article à supprimer.</p>";

    $bindingRows = "";
    foreach ($bindings as $entry) {
        $article = $entry["article"];
        $crowdfunding = $entry["crowdfunding"] ? "Oui" : "Non";
        $bindingRows .= "<tr><td>{$article->getId()}</td><td>" . articleLink($article)
            . "</td><td>{$entry["copies"]}</td><td>{$entry["onSale"]}</td><td>$crowdfunding</td></tr>";
    }

    $keptRows = "";
    foreach ($keptForCrowdfunding as $article) {
        $keptRows .= "<li>" . articleLink($article) . "</li>";
    }
    $keptNotice = $keptRows !== "" ? "
        <p>Conservés car liés à un financement participatif :</p>
        <ul>$keptRows</ul>" : "";

    $goodiesRows = "";
    foreach ($goodies as $entry) {
        $article = $entry["article"];
        $kept = $entry["total"] - $entry["deletable"];
        $crowdfunding = $entry["crowdfunding"] ? "Oui (conservé)" : "Non";
        $goodiesRows .= "<tr><td>{$article->getId()}</td><td>" . articleLink($article)
            . "</td><td>{$entry["deletable"]}</td><td>$kept</td><td>$crowdfunding</td></tr>";
    }

    $goodiesForm = $goodiesCopiesToDelete > 0 ? "
        <form method='post' class='fieldset'>
            <input type='hidden' name='action' value='delete-goodies-copies'>
            <p><strong>Toute suppression est définitive.</strong> Sauvegardez la base avant de continuer.</p>
            <button type='submit' class='btn btn-danger'>Supprimer $goodiesCopiesToDelete exemplaire(s) de goodies</button>
        </form>" : "<p>Aucun exemplaire de goodies à supprimer.</p>";

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

        <h2 class='mt-5'>Autres articles sans exemplaire à supprimer</h2>
        <p>
            Ces articles (papier, goodies, lots, etc.) n'ont aucun exemplaire : ils n'ont jamais été vendus
            et peuvent être supprimés. Les articles offerts par une offre spéciale ou liés à un financement
            participatif (contrepartie ou exemplaire de campagne) sont conservés.
        </p>
        <p>$otherCount article(s) sans exemplaire.</p>
        <table class='table'>
            <thead><tr><th>Id</th><th>Titre</th><th>Type</th><th>EAN</th></tr></thead>
            <tbody>$otherRows</tbody>
        </table>
        $otherForm
        $keptNotice

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

        <h2 class='mt-5'>Exemplaires de goodies à supprimer</h2>
        <p>
            Ces exemplaires de goodies Dystopia en stock vont être supprimés. Les exemplaires
            déjà vendus, rattachés à une commande, ou présents dans un panier sont conservés.
        </p>
        <p>$goodiesCopiesToDelete exemplaire(s) à supprimer.</p>
        <table class='table'>
            <thead><tr><th>Id</th><th>Titre</th><th>À supprimer</th><th>Conservés</th><th>Crowdfunding</th></tr></thead>
            <tbody>$goodiesRows</tbody>
        </table>
        $goodiesForm

        <h2 class='mt-5'>Éditions reliées et lots Yirminadingrad</h2>
        <p>
            Ces articles ont des exemplaires ou sont liés à un financement participatif : aucune action
            automatique, la page les liste seulement pour décision au cas par cas.
        </p>
        <table class='table'>
            <thead><tr><th>Id</th><th>Titre</th><th>Exemplaires</th><th>Non vendus</th><th>Crowdfunding</th></tr></thead>
            <tbody>$bindingRows</tbody>
        </table>
    ");
};

/**
 * Identifiants des articles liés à un financement participatif : présents dans
 * une contrepartie (cf_rewards.reward_articles) ou avec un exemplaire rattaché à
 * une campagne.
 *
 * @return array<int, true>
 */
function crowdfundingArticleIds(): array
{
    $connection = Propel::getConnection();
    $ids = [];

    $rewardArticles = $connection->query("SELECT reward_articles FROM cf_rewards")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rewardArticles as $rewardArticle) {
        $articleIds = json_decode($rewardArticle ?? "[]", true);
        foreach (is_array($articleIds) ? $articleIds : [] as $articleId) {
            $ids[(int) $articleId] = true;
        }
    }

    $campaignArticleIds = $connection->query(
        "SELECT DISTINCT article_id FROM stock WHERE campaign_id IS NOT NULL AND article_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($campaignArticleIds as $articleId) {
        $ids[(int) $articleId] = true;
    }

    return $ids;
}

function deletableCopiesQuery(Article $article): StockQuery
{
    return StockQuery::create()
        ->filterByArticle($article)
        ->filterBySellingDate(null, Criteria::ISNULL)
        ->filterByOrderId(null, Criteria::ISNULL)
        ->filterByCartId(null, Criteria::ISNULL);
}

function countDeletableCopies(Article $article): int
{
    return deletableCopiesQuery($article)->count();
}

function deleteDeletableCopies(Article $article): int
{
    return deletableCopiesQuery($article)->delete();
}

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
